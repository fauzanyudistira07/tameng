<?php

namespace App\Services\Engines\Adapters;

use App\Services\Engines\Contracts\EngineAdapter;
use App\Services\Engines\EngineRunPlan;
use App\Services\Engines\Support\EngineExecutionResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class WuppieFuzzAdapter implements EngineAdapter
{
    public function key(): string
    {
        return 'wuppiefuzz';
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
            return EngineExecutionResult::skipped($commandSpec, 'API_TARGET_URL_NOT_FOUND');
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

        $reportPath = $outputDirectory . DIRECTORY_SEPARATOR . 'wuppiefuzz-report.json';
        $hostMountOutput = $this->dockerStorageMountSource($outputDirectory);

        $engineModel = \App\Models\SecurityEngine::where('code', $this->key())->first();
        $cpuLimit = (string) ($engineModel?->cpu_limit ?? '1.5');
        $memoryLimit = ($engineModel?->memory_limit_mb ?? 1024).'m';
        $image = $engineModel?->container_image ?: config('secsys.wuppiefuzz_image', 'secsys/wuppiefuzz:latest');

        $dockerArgs = [
            $dockerBinary,
            'run',
            '--rm',
            '--cpus', $cpuLimit,
            '--memory', $memoryLimit,
            '--security-opt=no-new-privileges',
            '-v', "{$hostMountOutput}:/out:rw",
            $image,
            '--target', $targetUrl,
            '--output', '/out/wuppiefuzz-report.json',
            '--timeout', '180',
        ];

        $commandSpec['command'] = [
            'docker',
            'run',
            '--rm',
            '--cpus', $cpuLimit,
            '--memory', $memoryLimit,
            '-v', '[REDACTED_OUTPUT]:/out:rw',
            $image,
            '--target', $targetUrl,
            '--output', '/out/wuppiefuzz-report.json',
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
                failureReason: 'WUPPIEFUZZ_PROCESS_TIMEOUT',
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

        $items = $decoded['findings'] ?? $decoded['anomalies'] ?? $decoded['results'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        return collect($items)->map(function (array $item) use ($targetUrl): array {
            $method = $item['method'] ?? $item['http_method'] ?? 'POST';
            $endpoint = $item['endpoint'] ?? $item['path'] ?? '/api';
            $type = $item['type'] ?? $item['anomaly_type'] ?? 'API Fuzzing Anomaly';
            $statusCode = $item['status_code'] ?? $item['response_status'] ?? 500;
            $severity = $statusCode >= 500 ? 'high' : 'medium';

            return [
                'rule_id' => 'wuppiefuzz.' . Str::slug($type),
                'title' => "WuppieFuzz: {$type} on {$method} {$endpoint}",
                'severity' => $severity,
                'severity_raw' => (string) $statusCode,
                'confidence' => 0.80,
                'asset_type' => 'api_target',
                'asset_identifier' => $targetUrl,
                'endpoint' => $endpoint,
                'http_method' => $method,
                'cwe' => 'CWE-20',
                'owasp' => 'A04:2021-Insecure Design',
                'evidence_summary' => [
                    'type' => $type,
                    'status_code' => $statusCode,
                    'payload' => $item['payload'] ?? null,
                ],
                'evidence' => "Fuzzing triggered {$statusCode} anomaly: {$type} on {$method} {$endpoint}",
            ];
        })->values()->all();
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
