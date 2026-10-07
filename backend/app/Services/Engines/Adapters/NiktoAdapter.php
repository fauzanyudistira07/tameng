<?php

namespace App\Services\Engines\Adapters;

use App\Services\Engines\Contracts\EngineAdapter;
use App\Services\Engines\EngineRunPlan;
use App\Services\Engines\Support\EngineExecutionResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class NiktoAdapter implements EngineAdapter
{
    public function key(): string
    {
        return 'nikto';
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

        $reportPath = $outputDirectory . DIRECTORY_SEPARATOR . 'nikto-report.json';
        $hostMountOutput = $this->dockerStorageMountSource($outputDirectory);

        $engineModel = \App\Models\SecurityEngine::where('code', $this->key())->first();
        $cpuLimit = (string) ($engineModel?->cpu_limit ?? '1.5');
        $memoryLimit = ($engineModel?->memory_limit_mb ?? 1024).'m';
        $image = $engineModel?->container_image ?: 'frapsoft/nikto:latest';

        $dockerArgs = [
            $dockerBinary,
            'run',
            '--rm',
            '--cpus', $cpuLimit,
            '--memory', $memoryLimit,
            '--security-opt=no-new-privileges',
            '-v', "{$hostMountOutput}:/out:rw",
            $image,
            '-h', $targetUrl,
            '-Format', 'json',
            '-output', '/out/nikto-report.json',
            '-Tuning', '123b', // Interesting files, misconfigs, info disclosures
            '-Cgidirs', 'none',
            '-maxtime', '180s',
        ];

        $commandSpec['command'] = [
            'docker',
            'run',
            '--rm',
            '--cpus', $cpuLimit,
            '--memory', $memoryLimit,
            '-v', '[REDACTED_OUTPUT]:/out:rw',
            $image,
            '-h', $targetUrl,
            '-Format', 'json',
            '-output', '/out/nikto-report.json',
            '-Tuning', '123b',
            '-Cgidirs', 'none',
            '-maxtime', '180s',
        ];
        $commandSpec['scanner_execution'] = true;
        $commandSpec['container_image'] = $image;

        $timeoutSeconds = (int) config('secsys.engine_timeout_seconds', 300);
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
                failureReason: 'NIKTO_PROCESS_TIMEOUT',
                normalizedFindings: [],
            );
        } catch (\Throwable $e) {
            return new EngineExecutionResult(
                status: 'failed',
                exitCode: 1,
                commandSpec: $commandSpec,
                runtimeMetrics: ['error' => $e->getMessage()],
                rawOutput: null,
                failureReason: 'NIKTO_EXECUTION_ERROR: ' . $e->getMessage(),
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

        $vulnerabilities = $decoded['vulnerabilities'] ?? [];
        foreach ($vulnerabilities as $vuln) {
            if (! is_array($vuln)) {
                continue;
            }

            $msg = trim((string) ($vuln['msg'] ?? 'Nikto audit finding'));
            $id = $vuln['id'] ?? 'nikto_item';
            $uri = $vuln['url'] ?? '/';
            $method = strtoupper($vuln['method'] ?? 'GET');

            // Skip purely informational banners that are not real misconfigurations
            $msgLower = strtolower($msg);
            if (empty($msg) || str_starts_with($msgLower, 'no cgi directories') || str_starts_with($msgLower, '0 host(s) tested')) {
                continue;
            }

            // Dynamic severity determination based on vulnerability classification
            $severity = 'low';
            if (
                str_contains($msgLower, 'remote code') ||
                str_contains($msgLower, 'command execution') ||
                str_contains($msgLower, 'sql injection') ||
                str_contains($msgLower, 'rce')
            ) {
                $severity = 'critical';
            } elseif (
                str_contains($msgLower, 'traversal') ||
                str_contains($msgLower, 'unauthenticated') ||
                str_contains($msgLower, 'bypass') ||
                str_contains($msgLower, 'file inclusion') ||
                str_contains($msgLower, 'vulnerab')
            ) {
                $severity = 'high';
            } elseif (
                str_contains($msgLower, 'disclosure') ||
                str_contains($msgLower, 'misconfig') ||
                str_contains($msgLower, 'sensitive') ||
                str_contains($msgLower, 'backup') ||
                str_contains($msgLower, 'admin') ||
                str_contains($msgLower, 'debug')
            ) {
                $severity = 'medium';
            } elseif (
                str_contains($msgLower, 'x-frame-options') ||
                str_contains($msgLower, 'x-content-type') ||
                str_contains($msgLower, 'anti-clickjacking') ||
                str_contains($msgLower, 'header') ||
                str_contains($msgLower, 'cookie')
            ) {
                $severity = 'low';
            }

            $findings[] = [
                'rule_id' => 'nikto.' . $id,
                'title' => 'Nikto: ' . Str::limit($msg, 80),
                'severity' => $severity,
                'severity_raw' => $severity,
                'confidence' => 0.80,
                'asset_type' => 'web_target',
                'asset_identifier' => $targetUrl,
                'endpoint' => $uri,
                'http_method' => $method,
                'file_path' => null,
                'line_start' => 1,
                'line_end' => 1,
                'cwe' => 'CWE-16',
                'owasp' => 'A05:2021-Security Misconfiguration',
                'evidence_summary' => [
                    'id' => $id,
                    'method' => $method,
                    'url' => $uri,
                    'osvdb' => $vuln['OSVDB'] ?? null,
                    'message' => $msg,
                ],
                'evidence' => $msg,
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
