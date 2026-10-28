<?php

namespace App\Services\Engines\Adapters;

use App\Services\Engines\Contracts\EngineAdapter;
use App\Services\Engines\EngineRunPlan;
use App\Services\Engines\Support\EngineExecutionResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class WapitiAdapter implements EngineAdapter
{
    public function key(): string
    {
        return 'wapiti';
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

        $reportPath = $outputDirectory . DIRECTORY_SEPARATOR . 'wapiti-report.json';
        $hostMountOutput = $this->dockerStorageMountSource($outputDirectory);

        $engineModel = \App\Models\SecurityEngine::where('code', $this->key())->first();
        $cpuLimit = (string) ($engineModel?->cpu_limit ?? '1.5');
        $memoryLimit = ($engineModel?->memory_limit_mb ?? 1024).'m';
        $image = $engineModel?->container_image ?: config('secsys.wapiti_image', 'wapiti/wapiti:3.1.8');

        $dockerArgs = [
            $dockerBinary,
            'run',
            '--rm',
            '--cpus', $cpuLimit,
            '--memory', $memoryLimit,
            '--security-opt=no-new-privileges',
            '-v', "{$hostMountOutput}:/out:rw",
            $image,
            '-u', $targetUrl,
            '-f', 'json',
            '-o', '/out/wapiti-report.json',
            '--flush-session',
            '--scope', 'folder',
            '--max-scan-time', '180',
        ];

        $commandSpec['command'] = [
            'docker',
            'run',
            '--rm',
            '--cpus', $cpuLimit,
            '--memory', $memoryLimit,
            '-v', '[REDACTED_OUTPUT]:/out:rw',
            $image,
            '-u', $targetUrl,
            '-f', 'json',
            '-o', '/out/wapiti-report.json',
            '--flush-session',
            '--scope', 'folder',
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
                failureReason: 'WAPITI_PROCESS_TIMEOUT',
                normalizedFindings: [],
            );
        }
    }

    public function parseFindings(string $output, ?string $targetUrl = null): array
    {
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            return [];
        }

        $vulnerabilities = $decoded['vulnerabilities'] ?? [];
        $anomalies = $decoded['anomalies'] ?? [];
        $findings = [];

        foreach ($vulnerabilities as $category => $items) {
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                $method = $item['method'] ?? 'GET';
                $path = $item['path'] ?? $item['info'] ?? '/';
                $param = $item['parameter'] ?? '';
                $desc = $item['info'] ?? "Wapiti detected {$category}";

                $findings[] = [
                    'rule_id' => 'wapiti.' . Str::slug($category),
                    'title' => "Wapiti: {$category}" . ($param ? " in parameter '{$param}'" : ''),
                    'severity' => $this->categorySeverity($category),
                    'severity_raw' => $category,
                    'confidence' => 0.85,
                    'asset_type' => 'web_target',
                    'asset_identifier' => $targetUrl,
                    'endpoint' => $path,
                    'http_method' => $method,
                    'cwe' => $this->categoryCwe($category),
                    'owasp' => 'A03:2021-Injection',
                    'evidence_summary' => [
                        'category' => $category,
                        'parameter' => $param,
                        'method' => $method,
                        'path' => $path,
                    ],
                    'evidence' => "{$category} identified at {$method} {$path}" . ($param ? " (Parameter: {$param})" : ''),
                ];
            }
        }

        foreach ($anomalies as $category => $items) {
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                $findings[] = [
                    'rule_id' => 'wapiti.anomaly.' . Str::slug($category),
                    'title' => "Wapiti Anomaly: {$category}",
                    'severity' => 'low',
                    'severity_raw' => 'ANOMALY',
                    'confidence' => 0.70,
                    'asset_type' => 'web_target',
                    'asset_identifier' => $targetUrl,
                    'endpoint' => $item['path'] ?? '/',
                    'http_method' => $item['method'] ?? 'GET',
                    'cwe' => 'CWE-200',
                    'owasp' => 'A05:2021-Security Misconfiguration',
                    'evidence_summary' => $item,
                    'evidence' => $item['info'] ?? "Anomaly detected in {$category}",
                ];
            }
        }

        return $findings;
    }

    private function categorySeverity(string $category): string
    {
        $cat = strtolower($category);
        if (str_contains($cat, 'sql') || str_contains($cat, 'command') || str_contains($cat, 'rce') || str_contains($cat, 'ssrf')) {
            return 'critical';
        }
        if (str_contains($cat, 'xss') || str_contains($cat, 'csrf') || str_contains($cat, 'file_inclusion') || str_contains($cat, 'upload')) {
            return 'high';
        }
        if (str_contains($cat, 'redirect') || str_contains($cat, 'crlf') || str_contains($cat, 'xxe')) {
            return 'medium';
        }
        return 'low';
    }

    private function categoryCwe(string $category): string
    {
        $cat = strtolower($category);
        if (str_contains($cat, 'sql')) {
            return 'CWE-89';
        }
        if (str_contains($cat, 'xss')) {
            return 'CWE-79';
        }
        if (str_contains($cat, 'ssrf')) {
            return 'CWE-918';
        }
        if (str_contains($cat, 'csrf')) {
            return 'CWE-352';
        }
        if (str_contains($cat, 'crlf')) {
            return 'CWE-113';
        }
        return 'CWE-20';
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
