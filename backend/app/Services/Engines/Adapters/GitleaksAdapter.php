<?php

namespace App\Services\Engines\Adapters;

use App\Services\Engines\Contracts\EngineAdapter;
use App\Services\Engines\EngineRunPlan;
use App\Services\Engines\Support\EngineExecutionResult;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class GitleaksAdapter implements EngineAdapter
{
    public function key(): string
    {
        return 'gitleaks';
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

        $plan->scanJob->loadMissing('repository');
        $workspacePath = $plan->scanJob->repository?->metadata['local_path'] ?? null;

        if (! $workspacePath || ! File::isDirectory($workspacePath)) {
            return EngineExecutionResult::skipped($commandSpec, 'LOCAL_REPOSITORY_WORKSPACE_NOT_FOUND');
        }

        $runtime = config('secsys.engine_runtime');

        if ($runtime === 'docker') {
            return $this->executeWithDocker($plan, $commandSpec, $workspacePath);
        }

        if ($runtime === 'local_binary') {
            return $this->executeWithLocalBinary($plan, $commandSpec, $workspacePath);
        }

        return EngineExecutionResult::skipped($commandSpec, 'ENGINE_RUNTIME_NOT_SUPPORTED');
    }

    private function executeWithLocalBinary(EngineRunPlan $plan, array $commandSpec, string $workspacePath): EngineExecutionResult
    {
        if (! $this->binaryExists($plan->engine['external_binary'])) {
            return EngineExecutionResult::skipped($commandSpec, 'GITLEAKS_BINARY_NOT_FOUND');
        }

        $reportPath = $this->reportPath($plan);

        $command = [
            $plan->engine['external_binary'],
            'detect',
            '--source',
            $workspacePath,
            '--no-git',
            '--redact',
            '--report-format',
            'json',
            '--report-path',
            $reportPath,
        ];

        $process = new Process($command, $workspacePath);

        return $this->runProcess($process, [
            ...$commandSpec,
            'scanner_execution' => true,
            'command' => [
                $plan->engine['external_binary'],
                'detect',
                '--source',
                '[REDACTED_WORKSPACE]',
                '--no-git',
                '--redact',
                '--report-format',
                'json',
                '--report-path',
                '[REDACTED_REPORT_PATH]',
            ],
        ], $reportPath);
    }

    private function executeWithDocker(EngineRunPlan $plan, array $commandSpec, string $workspacePath): EngineExecutionResult
    {
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
        $image = config('secsys.gitleaks_image');
        $reportPath = $this->reportPath($plan);
        $outputDirectory = dirname($reportPath);

        $mountSource = $this->dockerMountSource($workspacePath);
        $outputMountSource = $this->dockerStorageMountSource($outputDirectory);

        $command = [
            $dockerBinary,
            'run',
            '--rm',
            '--network',
            'none',
            '--read-only',
            '--cap-drop',
            'ALL',
            '-v',
            "{$mountSource}:{$containerWorkspacePath}:ro",
            '-v',
            "{$outputMountSource}:{$containerOutputPath}:rw",
            $image,
            'detect',
            '--source',
            $containerWorkspacePath,
            '--no-git',
            '--redact',
            '--report-format',
            'json',
            '--report-path',
            "{$containerOutputPath}/gitleaks-report.json",
        ];

        $process = new Process($command);

        return $this->runProcess($process, [
            ...$commandSpec,
            'scanner_execution' => true,
            'container_image' => $image,
            'command' => [
                'docker',
                'run',
                '--rm',
                '--network',
                'none',
                '--read-only',
                '--cap-drop',
                'ALL',
                '-v',
                '[REDACTED_WORKSPACE]:'.$containerWorkspacePath.':ro',
                '-v',
                '[REDACTED_OUTPUT]:'.$containerOutputPath.':rw',
                $image,
                'detect',
                '--source',
                $containerWorkspacePath,
                '--no-git',
                '--redact',
                '--report-format',
                'json',
                '--report-path',
                $containerOutputPath.'/gitleaks-report.json',
            ],
        ], $reportPath);
    }

    private function runProcess(Process $process, array $commandSpec, string $reportPath): EngineExecutionResult
    {
        $process->setTimeout(config('secsys.engine_timeout_seconds'));

        $startedAt = microtime(true);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            return new EngineExecutionResult(
                status: 'failed',
                exitCode: null,
                commandSpec: $commandSpec,
                runtimeMetrics: [
                    'duration_ms' => $durationMs,
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
        $errorOutput = $process->getErrorOutput();
        $findings = $this->parseFindings($output);
        $isExpectedExit = $process->isSuccessful() || $process->getExitCode() === 1;

        return new EngineExecutionResult(
            status: $isExpectedExit ? 'completed' : 'failed',
            exitCode: $process->getExitCode(),
            commandSpec: $commandSpec,
            runtimeMetrics: [
                'duration_ms' => $durationMs,
                'finding_count' => count($findings),
                'stderr_present' => $errorOutput !== '',
            ],
            rawOutput: $output,
            failureReason: $isExpectedExit ? null : 'GITLEAKS_PROCESS_FAILED',
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
        $containerRoot = rtrim((string) config('secsys.repository_workspace_root'), DIRECTORY_SEPARATOR);
        $hostRoot = config('secsys.docker_host_workspace_root');

        return $this->mappedDockerPath($workspacePath, $containerRoot, $hostRoot);
    }

    private function dockerStorageMountSource(string $storagePath): string
    {
        $containerRoot = rtrim(storage_path('app/private'), DIRECTORY_SEPARATOR);
        $hostRoot = config('secsys.docker_host_storage_root');

        return $this->mappedDockerPath($storagePath, $containerRoot, $hostRoot);
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
            .DIRECTORY_SEPARATOR.'gitleaks';

        File::ensureDirectoryExists($directory);

        return $directory.DIRECTORY_SEPARATOR.'gitleaks-report.json';
    }

    private function parseFindings(string $output): array
    {
        if ($output === '') {
            return [];
        }

        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)->map(fn (array $finding): array => [
            'rule_id' => $finding['RuleID'] ?? 'gitleaks.secret',
            'title' => $finding['Description'] ?? 'Secret detected by Gitleaks',
            'severity' => 'critical',
            'severity_raw' => 'secret',
            'confidence' => 0.85,
            'asset_type' => 'repository',
            'file_path' => $finding['File'] ?? null,
            'line_start' => $finding['StartLine'] ?? null,
            'line_end' => $finding['EndLine'] ?? null,
            'cwe' => 'CWE-798',
            'evidence_summary' => [
                'rule_id' => $finding['RuleID'] ?? null,
                'description' => $finding['Description'] ?? null,
                'secret_redacted' => true,
            ],
            'evidence' => 'Gitleaks detected a secret-like value. Secret content is redacted and not stored.',
        ])->values()->all();
    }
}
