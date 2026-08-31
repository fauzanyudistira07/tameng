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
        $port = $parsed['port'] ?? ($parsed['scheme'] === 'http' ? 80 : 443);
        $sslTarget = "{$host}:{$port}";

        $dockerBinary = (string) config('secsys.docker_binary', 'docker');
        $outputDirectory = storage_path('app/private/engine-output/' . $plan->scanJob->id . '/testssl');
        File::ensureDirectoryExists($outputDirectory);
        $reportPath = $outputDirectory . DIRECTORY_SEPARATOR . 'testssl-report.json';

        $engineModel = \App\Models\SecurityEngine::where('code', $this->key())->first();
        $cpuLimit = (string) ($engineModel?->cpu_limit ?? '1.5');
        $memoryLimit = ($engineModel?->memory_limit_mb ?? 1024).'m';
        $image = $engineModel?->container_image ?: 'drwetter/testssl.sh:3.0';

        $dockerArgs = [
            $dockerBinary,
            'run',
            '--rm',
            '--cpus', $cpuLimit,
            '--memory', $memoryLimit,
            '--security-opt=no-new-privileges',
            '-v', "{$outputDirectory}:/out:rw",
            $image,
            '--jsonfile-pretty', '/out/testssl-report.json',
            '--fast',
            '--ip', 'one',
            '--quiet',
            '--warnings', 'off',
            '--connect-timeout', '3',
            '--openssl-timeout', '3',
            $sslTarget,
        ];

        $commandSpec['command'] = $dockerArgs;
        $commandSpec['scanner_execution'] = true;
        $commandSpec['container_image'] = $image;

        $process = new Process($dockerArgs, null, null, null, 300);

        try {
            $process->run();
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
                runtimeMetrics: ['error' => 'timeout'],
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

    private function parseFindings(string $output): array
    {
        $findings = [];
        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            return [];
        }

        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }

            $severityStr = strtoupper($item['severity'] ?? 'INFO');
            $severity = match ($severityStr) {
                'CRITICAL', 'FATAL' => 'critical',
                'HIGH' => 'high',
                'MEDIUM' => 'medium',
                'LOW' => 'low',
                default => 'informational',
            };

            // Only capture meaningful findings / warnings / vulnerabilities
            if ($severity === 'informational' && in_array($item['finding'] ?? '', ['OK', 'not vulnerable', 'not offered', 'supported', 'matches'])) {
                continue;
            }

            $id = $item['id'] ?? 'ssl_check';
            $findingText = $item['finding'] ?? 'Finding detected';

            $findings[] = [
                'rule_id' => 'testssl.' . $id,
                'title' => 'TLS/SSL Audit: ' . Str::headline(str_replace('_', ' ', $id)),
                'description' => "TestSSL mendeteksi kondisi: {$findingText}",
                'severity' => $severity,
                'file_path' => null,
                'line_number' => null,
                'metadata' => [
                    'id' => $id,
                    'finding' => $findingText,
                    'cve' => $item['cve'] ?? null,
                    'cwe' => $item['cwe'] ?? 'CWE-310',
                ],
            ];
        }

        return $findings;
    }
}
