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
        $outputDirectory = storage_path('app/private/engine-output/' . $plan->scanJob->id . '/nikto');
        File::ensureDirectoryExists($outputDirectory);
        $reportPath = $outputDirectory . DIRECTORY_SEPARATOR . 'nikto-report.json';

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
            '-v', "{$outputDirectory}:/out:rw",
            $image,
            '-h', $targetUrl,
            '-Format', 'json',
            '-output', '/out/nikto-report.json',
            '-Tuning', '123b', // Interesting files, misconfigs, info disclosures
            '-Cgidirs', 'none',
            '-maxtime', '180s',
        ];

        $commandSpec['command'] = $dockerArgs;
        $commandSpec['scanner_execution'] = true;
        $commandSpec['container_image'] = $image;

        $process = new Process($dockerArgs, null, null, null, 240);

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
                failureReason: 'NIKTO_EXECUTION_ERROR',
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

        $vulnerabilities = $decoded['vulnerabilities'] ?? [];
        foreach ($vulnerabilities as $vuln) {
            if (! is_array($vuln)) {
                continue;
            }

            $msg = $vuln['msg'] ?? 'Nikto audit finding';
            $id = $vuln['id'] ?? 'nikto_item';
            $uri = $vuln['url'] ?? '/';

            $findings[] = [
                'rule_id' => 'nikto.' . $id,
                'title' => 'Web Server Audit: ' . Str::limit($msg, 80),
                'description' => $msg,
                'severity' => 'low',
                'file_path' => $uri,
                'line_number' => null,
                'metadata' => [
                    'id' => $id,
                    'method' => $vuln['method'] ?? 'GET',
                    'url' => $uri,
                    'osvdb' => $vuln['OSVDB'] ?? null,
                ],
            ];
        }

        return $findings;
    }
}
