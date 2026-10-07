<?php

namespace App\Services\Engines\Adapters;

use App\Services\Engines\Contracts\EngineAdapter;
use App\Services\Engines\EngineRunPlan;
use App\Services\Engines\Support\EngineExecutionResult;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class ZapAdapter implements EngineAdapter
{
    public function key(): string
    {
        return 'zap';
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

        if (! Str::startsWith($targetUrl, ['http://', 'https://'])) {
            $targetUrl = 'https://' . $targetUrl;
        }

        $dockerBinary = (string) config('secsys.docker_binary', 'docker');
        if (! $this->binaryExists($dockerBinary)) {
            return EngineExecutionResult::skipped($commandSpec, 'DOCKER_BINARY_NOT_FOUND');
        }

        $outputDirectory = rtrim((string) config('secsys.engine_output_root', storage_path('app/private/engine-output')), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$plan->scanJob->id
            .DIRECTORY_SEPARATOR.$this->key();

        File::ensureDirectoryExists($outputDirectory);
        @chmod($outputDirectory, 0777);

        $reportPath = $outputDirectory . DIRECTORY_SEPARATOR . 'zap-report.json';
        $hostMountOutput = $this->dockerStorageMountSource($outputDirectory);

        $engineModel = \App\Models\SecurityEngine::where('code', $this->key())->first();
        $cpuLimit = (string) ($engineModel?->cpu_limit ?? '2.0');
        $memoryLimit = ($engineModel?->memory_limit_mb ?? 2048).'m';
        $image = $engineModel?->container_image ?: (config('secsys.zap_image') ?: 'ghcr.io/zaproxy/zaproxy:stable');

        // Check for authenticated cookie/header
        $zapExtraArgs = [];
        if ($target && ! empty($target->metadata['auth'])) {
            $auth = $target->metadata['auth'];
            try {
                if (! empty($auth['resolved_cookie_value'])) {
                    $cookieVal = Crypt::decryptString($auth['resolved_cookie_value']);
                    $zapExtraArgs[] = '-z';
                    $zapExtraArgs[] = "-config replacer.full_list(0).description=auth -config replacer.full_list(0).enabled=true -config replacer.full_list(0).matchtype=REQ_HEADER -config replacer.full_list(0).matchstr=Cookie -config replacer.full_list(0).replacement=\"{$cookieVal}\"";
                } elseif ($auth['type'] === 'cookie' && ! empty($auth['cookie_value'])) {
                    $cookieVal = Crypt::decryptString($auth['cookie_value']);
                    $zapExtraArgs[] = '-z';
                    $zapExtraArgs[] = "-config replacer.full_list(0).description=auth -config replacer.full_list(0).enabled=true -config replacer.full_list(0).matchtype=REQ_HEADER -config replacer.full_list(0).matchstr=Cookie -config replacer.full_list(0).replacement=\"{$cookieVal}\"";
                } elseif ($auth['type'] === 'header' && ! empty($auth['header_value'])) {
                    $headerName = $auth['header_name'] ?? 'Authorization';
                    $headerVal = Crypt::decryptString($auth['header_value']);
                    $zapExtraArgs[] = '-z';
                    $zapExtraArgs[] = "-config replacer.full_list(0).description=auth -config replacer.full_list(0).enabled=true -config replacer.full_list(0).matchtype=REQ_HEADER -config replacer.full_list(0).matchstr={$headerName} -config replacer.full_list(0).replacement=\"{$headerVal}\"";
                }
            } catch (\Throwable) {
                // Ignore decrypt error
            }
        }

        $dockerArgs = [
            $dockerBinary,
            'run',
            '--rm',
            '--user', '0:0',
            '--cpus', $cpuLimit,
            '--memory', $memoryLimit,
            '--security-opt=no-new-privileges',
            '-v', "{$hostMountOutput}:/zap/wrk:rw",
            $image,
            'zap-baseline.py',
            '-t', $targetUrl,
            '-J', 'zap-report.json',
            '-m', '1', // 1 minute max spider
            '-T', '3', // 3 minutes max passive scan & startup
            '-I',
            ...$zapExtraArgs,
        ];

        $commandSpec['command'] = [
            'docker',
            'run',
            '--rm',
            '--user', '0:0',
            '--cpus', $cpuLimit,
            '--memory', $memoryLimit,
            '-v', '[REDACTED_OUTPUT]:/zap/wrk:rw',
            $image,
            'zap-baseline.py',
            '-t', $targetUrl,
            '-J', 'zap-report.json',
            '-m', '1',
            '-T', '3',
            '-I',
        ];
        $commandSpec['scanner_execution'] = true;
        $commandSpec['container_image'] = $image;

        $timeoutSeconds = (int) config('secsys.engine_timeout_seconds', 400);
        $process = new Process($dockerArgs, null, null, null, $timeoutSeconds);

        $startTime = microtime(true);
        try {
            $process->run();
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            $exitCode = $process->getExitCode();

            $output = '';
            if (File::exists($reportPath)) {
                $output = File::get($reportPath);
            } else {
                $output = $process->getOutput() ?: $process->getErrorOutput();
            }

            $findings = $this->parseFindings($output, $targetUrl);

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
        } catch (ProcessTimedOutException $e) {
            return new EngineExecutionResult(
                status: 'failed',
                exitCode: 124,
                commandSpec: $commandSpec,
                runtimeMetrics: ['error' => 'timeout', 'timeout_seconds' => $timeoutSeconds],
                rawOutput: null,
                failureReason: 'ZAP_PROCESS_TIMEOUT',
                normalizedFindings: [],
            );
        } catch (\Throwable $e) {
            return new EngineExecutionResult(
                status: 'failed',
                exitCode: 1,
                commandSpec: $commandSpec,
                runtimeMetrics: ['error' => $e->getMessage()],
                rawOutput: null,
                failureReason: 'ZAP_EXECUTION_ERROR: ' . $e->getMessage(),
                normalizedFindings: [],
            );
        }
    }

    public function parseFindings(string $output, string $targetUrl = ''): array
    {
        $findings = [];
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            return [];
        }

        $sites = $decoded['site'] ?? [];
        if (! isset($sites[0]) && is_array($sites) && ! empty($sites)) {
            $sites = [$sites];
        }

        foreach ($sites as $site) {
            $siteName = $site['@name'] ?? $targetUrl;
            $alerts = $site['alerts'] ?? [];
            foreach ($alerts as $alert) {
                if (! is_array($alert)) {
                    continue;
                }

                $riskCode = (string) ($alert['riskcode'] ?? '0');
                $severity = match ($riskCode) {
                    '3' => 'high',
                    '2' => 'medium',
                    '1' => 'low',
                    default => 'informational',
                };

                $pluginId = $alert['pluginid'] ?? 'zap_alert';
                $name = $alert['alert'] ?? $alert['name'] ?? 'OWASP ZAP Security Alert';
                $cleanDesc = strip_tags($alert['desc'] ?? '');
                $cleanSolution = strip_tags($alert['solution'] ?? '');

                $instances = $alert['instances'] ?? [];
                $firstInstance = isset($instances[0]) && is_array($instances[0]) ? $instances[0] : [];
                $uri = $firstInstance['uri'] ?? $siteName;
                $method = $firstInstance['method'] ?? 'GET';
                $evidence = $firstInstance['evidence'] ?? '';
                $param = $firstInstance['param'] ?? '';
                $attack = $firstInstance['attack'] ?? '';

                $cweId = ! empty($alert['cweid']) ? 'CWE-' . $alert['cweid'] : null;
                $wascId = ! empty($alert['wascid']) ? 'WASC-' . $alert['wascid'] : null;

                $evidenceText = '';
                if ($evidence) {
                    $evidenceText .= "Evidence: {$evidence}\n";
                }
                if ($param) {
                    $evidenceText .= "Parameter: {$param}\n";
                }
                if ($attack) {
                    $evidenceText .= "Attack Payload: {$attack}\n";
                }
                if (empty(trim($evidenceText))) {
                    $evidenceText = $cleanDesc ?: $name;
                }

                $findings[] = [
                    'rule_id' => 'zap.' . $pluginId,
                    'title' => 'OWASP ZAP: ' . $name,
                    'severity' => $severity,
                    'severity_raw' => $riskCode,
                    'confidence' => 0.85,
                    'asset_type' => 'web_target',
                    'asset_identifier' => $targetUrl ?: $siteName,
                    'endpoint' => $uri,
                    'http_method' => strtoupper($method),
                    'file_path' => null,
                    'line_start' => 1,
                    'line_end' => 1,
                    'cwe' => $cweId,
                    'owasp' => 'A05:2021-Security Misconfiguration',
                    'evidence_summary' => [
                        'plugin_id' => $pluginId,
                        'risk_code' => $riskCode,
                        'cwe_id' => $cweId,
                        'wasc_id' => $wascId,
                        'endpoint' => $uri,
                        'method' => $method,
                        'param' => $param,
                        'remediation' => $cleanSolution,
                        'reference' => strip_tags($alert['reference'] ?? ''),
                        'description' => $cleanDesc,
                    ],
                    'evidence' => trim($evidenceText),
                ];
            }
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
