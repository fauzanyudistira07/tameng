<?php

namespace App\Services\Engines\Adapters;

use App\Services\Engines\Contracts\EngineAdapter;
use App\Services\Engines\EngineRunPlan;
use App\Services\Engines\Support\EngineExecutionResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class TestSslAdapter implements EngineAdapter
{
    public function key(): string
    {
        return 'testssl';
    }

    public function execute(EngineRunPlan $plan): EngineExecutionResult
    {
        $commandSpec = [
            ...$plan->toCommandSpec(),
            'mode' => 'real_adapter_guarded',
            'runtime' => config('secsys.engine_runtime'),
            'scanner_execution' => false,
        ];

        if (! config('secsys.real_engines_enabled')) {
            return EngineExecutionResult::skipped($commandSpec, 'REAL_ENGINE_EXECUTION_DISABLED');
        }

        if (config('secsys.engine_runtime') !== 'docker') {
            return EngineExecutionResult::skipped($commandSpec, 'ENGINE_RUNTIME_NOT_SUPPORTED');
        }

        $plan->scanJob->loadMissing('target');
        $target = $plan->scanJob->target;
        $targetUrl = $target?->base_url ?? $target?->hostname;

        if (! $targetUrl) {
            return EngineExecutionResult::skipped($commandSpec, 'WEB_TARGET_URL_NOT_FOUND');
        }

        // Parse host and port
        $parsed = parse_url($targetUrl);
        $host = $parsed['host'] ?? $targetUrl;
        $scheme = $parsed['scheme'] ?? 'https';
        $port = $parsed['port'] ?? ($scheme === 'http' ? 80 : 443);
        $sslTarget = "{$host}:{$port}";

        $dockerBinary = (string) config('secsys.docker_binary', 'docker');
        if (! $this->binaryExists($dockerBinary)) {
            return EngineExecutionResult::skipped($commandSpec, 'DOCKER_BINARY_NOT_FOUND');
        }

        $outputDirectory = rtrim((string) config('secsys.engine_output_root', storage_path('app/private/engine-output')), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$plan->scanJob->id
            .DIRECTORY_SEPARATOR.$this->key();

        File::ensureDirectoryExists($outputDirectory);
        @chmod($outputDirectory, 0777);

        $reportPath = $outputDirectory.DIRECTORY_SEPARATOR.'testssl-report.json';
        $containerOutputPath = config('secsys.container_output_path', '/out');
        $hostMountOutput = $this->dockerStorageMountSource($outputDirectory);

        $engineModel = \App\Models\SecurityEngine::where('code', $this->key())->first();
        $cpuLimit = (string) ($engineModel?->cpu_limit ?? '1.5');
        $memoryLimit = ($engineModel?->memory_limit_mb ?? 1536).'m';
        $image = $engineModel?->container_image ?: (config('secsys.testssl_image') ?: 'drwetter/testssl.sh:3.0');

        $dockerArgs = [
            $dockerBinary,
            'run',
            '--rm',
            '--user', '0:0',
            '--cpus', $cpuLimit,
            '--memory', $memoryLimit,
            '--security-opt=no-new-privileges',
            '-v', "{$hostMountOutput}:{$containerOutputPath}:rw",
            $image,
            '--jsonfile-pretty', "{$containerOutputPath}/testssl-report.json",
            '--fast',
            '--ip', 'one',
            '--quiet',
            '--warnings', 'off',
            '--connect-timeout', '5',
            '--openssl-timeout', '5',
            $sslTarget,
        ];

        $commandSpec['command'] = [
            'docker',
            'run',
            '--rm',
            '--user', '0:0',
            '--cpus', $cpuLimit,
            '--memory', $memoryLimit,
            '-v', "[REDACTED_OUTPUT]:{$containerOutputPath}:rw",
            $image,
            '--jsonfile-pretty', "{$containerOutputPath}/testssl-report.json",
            '--fast',
            '--ip', 'one',
            '--quiet',
            '--warnings', 'off',
            $sslTarget,
        ];
        $commandSpec['scanner_execution'] = true;
        $commandSpec['container_image'] = $image;

        $timeoutSeconds = (int) config('secsys.engine_timeout_seconds', 600);
        $process = new Process($dockerArgs, null, null, null, $timeoutSeconds);

        $startedAt = microtime(true);
        try {
            $process->run();
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $exitCode = $process->getExitCode();

            $output = '';
            if (File::exists($reportPath)) {
                $output = File::get($reportPath);
            } else {
                $output = $process->getOutput() ?: $process->getErrorOutput();
            }

            $findings = $this->parseFindings($output);

            return new EngineExecutionResult(
                status: 'completed',
                exitCode: $exitCode ?? 0,
                commandSpec: $commandSpec,
                runtimeMetrics: [
                    'duration_ms' => $durationMs,
                    'finding_count' => count($findings),
                    'stdout_preview' => Str::limit($process->getOutput(), 500),
                ],
                rawOutput: $output,
                failureReason: null,
                normalizedFindings: $findings,
            );
        } catch (ProcessTimedOutException) {
            return new EngineExecutionResult(
                status: 'failed',
                exitCode: 124,
                commandSpec: $commandSpec,
                runtimeMetrics: [
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'finding_count' => 0,
                    'timeout_seconds' => $timeoutSeconds,
                ],
                rawOutput: null,
                failureReason: 'TESTSSL_PROCESS_TIMEOUT',
                normalizedFindings: [],
            );
        } catch (\Throwable $e) {
            return new EngineExecutionResult(
                status: 'failed',
                exitCode: 1,
                commandSpec: $commandSpec,
                runtimeMetrics: ['error' => $e->getMessage()],
                rawOutput: null,
                failureReason: 'TESTSSL_EXECUTION_ERROR',
                normalizedFindings: [],
            );
        }
    }

    public function parseFindings(string $output): array
    {
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            return [];
        }

        $items = [];

        // Check if output is structured format with scanResult
        if (isset($decoded['scanResult']) && is_array($decoded['scanResult'])) {
            foreach ($decoded['scanResult'] as $scan) {
                if (! is_array($scan)) {
                    continue;
                }
                foreach (['protocols', 'ciphers', 'pfs', 'serverPreferences', 'serverDefaults', 'headerResponse', 'vulnerabilities', 'cipherTests', 'browserSimulations', 'rating'] as $section) {
                    if (! empty($scan[$section]) && is_array($scan[$section])) {
                        foreach ($scan[$section] as $entry) {
                            if (is_array($entry)) {
                                $entry['_section'] = $section;
                                $items[] = $entry;
                            }
                        }
                    }
                }
            }
        } elseif (array_is_list($decoded)) {
            // Flat list format
            $items = $decoded;
        } else {
            // Check top-level keys
            foreach ($decoded as $key => $subItems) {
                if (is_array($subItems)) {
                    foreach ($subItems as $sub) {
                        if (is_array($sub)) {
                            $sub['_section'] = $key;
                            $items[] = $sub;
                        }
                    }
                }
            }
        }

        $findings = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $severityStr = strtoupper($item['severity'] ?? 'INFO');
            $severity = match ($severityStr) {
                'CRITICAL', 'FATAL' => 'critical',
                'HIGH', 'ERROR' => 'high',
                'MEDIUM', 'WARN' => 'medium',
                'LOW' => 'low',
                default => 'informational',
            };

            $findingText = trim((string) ($item['finding'] ?? ''));
            $id = $item['id'] ?? 'ssl_check';

            // Filter out benign informational status messages
            if ($severity === 'informational' && in_array(strtolower($findingText), [
                'ok', 'not vulnerable', 'not offered', 'supported', 'matches', 'yes', 'no', 'none', '',
            ], true)) {
                continue;
            }

            $cve = $item['cve'] ?? null;
            $cwe = $item['cwe'] ?? 'CWE-310';
            $section = $item['_section'] ?? 'general';

            $readableId = str_replace('_', ' ', $id);
            if (! ctype_upper(str_replace([' ', '-', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], '', $readableId))) {
                $readableId = ucwords($readableId);
            }

            $title = 'TLS/SSL: '.$readableId;
            if ($cve) {
                $title .= " ({$cve})";
            }

            $findings[] = [
                'rule_id' => 'testssl.'.$id,
                'title' => $title,
                'severity' => $severity,
                'severity_raw' => $severityStr,
                'confidence' => 0.90,
                'asset_type' => 'web_target',
                'file_path' => null,
                'line_start' => 1,
                'line_end' => 1,
                'cve' => $cve,
                'cwe' => $cwe,
                'owasp' => 'A02:2021-Cryptographic Failures',
                'evidence_summary' => [
                    'check_id' => $id,
                    'section' => $section,
                    'finding' => $findingText,
                    'cve' => $cve,
                    'cwe' => $cwe,
                ],
                'evidence' => "TestSSL ({$section}): {$findingText}",
            ];
        }

        return $findings;
    }

    private function dockerStorageMountSource(string $storagePath): string
    {
        $path = rtrim($storagePath, DIRECTORY_SEPARATOR);
        $containerRoot = rtrim(storage_path('app/private'), DIRECTORY_SEPARATOR);
        $hostRoot = config('secsys.docker_host_storage_root');

        if (! is_string($hostRoot) || $hostRoot === '') {
            return $path;
        }

        $hostRoot = rtrim($hostRoot, '/\\');

        if ($path === $containerRoot) {
            return $hostRoot;
        }

        $relativePath = ltrim(substr($path, strlen($containerRoot)), '/\\');

        return $hostRoot.DIRECTORY_SEPARATOR.$relativePath;
    }

    private function binaryExists(string $binary): bool
    {
        if (File::exists($binary)) {
            return true;
        }

        $command = PHP_OS_FAMILY === 'Windows'
            ? ['where.exe', $binary]
            : ['/bin/sh', '-lc', 'command -v '.escapeshellarg($binary)];

        $process = new Process($command);
        $process->setTimeout(5);
        $process->run();

        return $process->isSuccessful();
    }
}
