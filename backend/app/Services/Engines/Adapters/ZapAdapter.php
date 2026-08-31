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
        $outputDirectory = storage_path('app/private/engine-output/' . $plan->scanJob->id . '/zap');
        File::ensureDirectoryExists($outputDirectory);
        $reportPath = $outputDirectory . DIRECTORY_SEPARATOR . 'zap-report.json';

        $engineModel = \App\Models\SecurityEngine::where('code', $this->key())->first();
        $cpuLimit = (string) ($engineModel?->cpu_limit ?? '2.0');
        $memoryLimit = ($engineModel?->memory_limit_mb ?? 2048).'m';
        $image = $engineModel?->container_image ?: 'ghcr.io/zaproxy/zaproxy:stable';

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
                }
            } catch (\Throwable) {
                // Ignore decrypt error
            }
        }

        $dockerArgs = [
            $dockerBinary,
            'run',
            '--rm',
            '--cpus', $cpuLimit,
            '--memory', $memoryLimit,
            '--security-opt=no-new-privileges',
            '-v', "{$outputDirectory}:/zap/wrk:rw",
            '-t',
            $image,
            'zap-baseline.py',
            '-t', $targetUrl,
            '-J', 'zap-report.json',
            '-m', '3', // 3 minutes max spider
            ...$zapExtraArgs,
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
                failureReason: 'ZAP_EXECUTION_ERROR',
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

        $sites = $decoded['site'] ?? [];
        foreach ($sites as $site) {
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

                $findings[] = [
                    'rule_id' => 'zap.' . $pluginId,
                    'title' => 'OWASP ZAP: ' . $name,
                    'description' => Str::limit($cleanDesc, 200),
                    'severity' => $severity,
                    'file_path' => null,
                    'line_number' => null,
                    'metadata' => [
                        'plugin_id' => $pluginId,
                        'risk_code' => $riskCode,
                        'cwe_id' => $alert['cweid'] ?? null,
                        'wasc_id' => $alert['wascid'] ?? null,
                        'remediation' => $cleanSolution,
                        'reference' => strip_tags($alert['reference'] ?? ''),
                    ],
                ];
            }
        }

        return $findings;
    }
}
