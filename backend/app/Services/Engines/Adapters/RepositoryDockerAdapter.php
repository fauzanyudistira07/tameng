<?php

namespace App\Services\Engines\Adapters;

use App\Services\Engines\Contracts\EngineAdapter;
use App\Services\Engines\EngineRunPlan;
use App\Services\Engines\Support\EngineExecutionResult;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

abstract class RepositoryDockerAdapter implements EngineAdapter
{
    abstract protected function imageConfigKey(): string;

    abstract protected function containerCommand(string $containerWorkspacePath, string $containerOutputPath): array;

    abstract protected function reportFilename(): string;

    abstract protected function parseFindings(string $output): array;

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

        $plan->scanJob->loadMissing('repository');
        $workspacePath = $plan->scanJob->repository?->metadata['local_path'] ?? null;

        if (! $workspacePath || ! File::isDirectory($workspacePath)) {
            return EngineExecutionResult::skipped($commandSpec, 'LOCAL_REPOSITORY_WORKSPACE_NOT_FOUND');
        }

        $dockerBinary = (string) config('secsys.docker_binary', 'docker');

        if (! $this->binaryExists($dockerBinary)) {
            return EngineExecutionResult::skipped($commandSpec, 'DOCKER_BINARY_NOT_FOUND');
        }

        if (! $this->dockerResponds($dockerBinary)) {
            return new EngineExecutionResult(
                status: 'failed',
                exitCode: null,
                commandSpec: $commandSpec,
                runtimeMetrics: [
                    'finding_count' => 0,
                    'docker_preflight_timeout_seconds' => 15,
                ],
                rawOutput: null,
                failureReason: 'DOCKER_CLI_NOT_RESPONDING',
                normalizedFindings: [],
            );
        }

        $containerWorkspacePath = config('secsys.container_workspace_path');
        $containerOutputPath = config('secsys.container_output_path');
        $engineModel = \App\Models\SecurityEngine::where('code', $this->key())->first();
        $cpuLimit = (string) ($engineModel?->cpu_limit ?? '1.5');
        $memoryLimit = ($engineModel?->memory_limit_mb ?? 2048).'m';
        $image = $engineModel?->container_image ?: config($this->imageConfigKey());
        $reportPath = $this->reportPath($plan);
        $outputDirectory = dirname($reportPath);

        $command = [
            $dockerBinary,
            'run',
            '--rm',
            '--cpus',
            $cpuLimit,
            '--memory',
            $memoryLimit,
            '--security-opt=no-new-privileges',
            '-v',
            $this->dockerMountSource($workspacePath).":{$containerWorkspacePath}:ro",
            '-v',
            $this->dockerStorageMountSource($outputDirectory).":{$containerOutputPath}:rw",
            $image,
            ...$this->containerCommand($containerWorkspacePath, $containerOutputPath),
        ];

        $process = new Process($command);

        return $this->runProcess($process, [
            ...$commandSpec,
            'scanner_execution' => true,
            'container_image' => $image,
            'resource_class' => $engineModel?->resource_class ?? 'MEDIUM',
            'command' => [
                'docker',
                'run',
                '--rm',
                '--cpus',
                $cpuLimit,
                '--memory',
                $memoryLimit,
                '-v',
                '[REDACTED_WORKSPACE]:'.$containerWorkspacePath.':ro',
                '-v',
                '[REDACTED_OUTPUT]:'.$containerOutputPath.':rw',
                $image,
                ...$this->containerCommand($containerWorkspacePath, $containerOutputPath),
            ],
        ], $reportPath);
    }

    protected function successfulExitCodes(): array
    {
        return [0];
    }

    private function runProcess(Process $process, array $commandSpec, string $reportPath): EngineExecutionResult
    {
        $process->setTimeout(config('secsys.engine_timeout_seconds'));

        $startedAt = microtime(true);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new EngineExecutionResult(
                status: 'failed',
                exitCode: null,
                commandSpec: $commandSpec,
                runtimeMetrics: [
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'finding_count' => 0,
                    'stderr_present' => false,
                    'timeout_seconds' => config('secsys.engine_timeout_seconds'),
                ],
                rawOutput: null,
                failureReason: 'ENGINE_PROCESS_TIMEOUT',
                normalizedFindings: [],
            );
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $output = File::exists($reportPath) ? File::get($reportPath) : $process->getOutput();
        $findings = $this->parseFindings($output);
        $isExpectedExit = in_array($process->getExitCode(), $this->successfulExitCodes(), true);

        return new EngineExecutionResult(
            status: $isExpectedExit ? 'completed' : 'failed',
            exitCode: $process->getExitCode(),
            commandSpec: $commandSpec,
            runtimeMetrics: [
                'duration_ms' => $durationMs,
                'finding_count' => count($findings),
                'stderr_present' => $process->getErrorOutput() !== '',
            ],
            rawOutput: $output,
            failureReason: $isExpectedExit ? null : strtoupper($this->key()).'_PROCESS_FAILED',
            normalizedFindings: $findings,
        );
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

    private function dockerResponds(string $dockerBinary): bool
    {
        $process = new Process([$dockerBinary, 'version', '--format', '{{.Server.Version}}']);
        $process->setTimeout(15);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return false;
        }

        return $process->isSuccessful();
    }

    private function dockerMountSource(string $workspacePath): string
    {
        return $this->mappedDockerPath(
            $workspacePath,
            rtrim((string) config('secsys.repository_workspace_root'), DIRECTORY_SEPARATOR),
            config('secsys.docker_host_workspace_root'),
        );
    }

    private function dockerStorageMountSource(string $storagePath): string
    {
        return $this->mappedDockerPath(
            $storagePath,
            rtrim(storage_path('app/private'), DIRECTORY_SEPARATOR),
            config('secsys.docker_host_storage_root'),
        );
    }

    private function mappedDockerPath(string $path, string $containerRoot, mixed $hostRoot): string
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);

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

    private function reportPath(EngineRunPlan $plan): string
    {
        $directory = rtrim((string) config('secsys.engine_output_root'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$plan->scanJob->id
            .DIRECTORY_SEPARATOR.$this->key();

        File::ensureDirectoryExists($directory);

        return $directory.DIRECTORY_SEPARATOR.$this->reportFilename();
    }
}
