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

class NucleiAdapter implements EngineAdapter
{
    public function key(): string
    {
        return 'nuclei';
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

        // Validate target URL format
        if (! Str::startsWith($targetUrl, ['http://', 'https://'])) {
            $targetUrl = 'https://' . $targetUrl;
        }

        $dockerBinary = (string) config('secsys.docker_binary', 'docker');
        if (! $this->binaryExists($dockerBinary)) {
            return EngineExecutionResult::skipped($commandSpec, 'DOCKER_BINARY_NOT_FOUND');
        }

        $containerOutputPath = config('secsys.container_output_path', '/out');
        $engineModel = \App\Models\SecurityEngine::where('code', $this->key())->first();
        $cpuLimit = (string) ($engineModel?->cpu_limit ?? '2.0');
        $memoryLimit = ($engineModel?->memory_limit_mb ?? 2048).'m';
        $image = $engineModel?->container_image ?: config('secsys.nuclei_image', 'projectdiscovery/nuclei:latest');
        
        $outputDirectory = storage_path('app/private/engine-output/' . $plan->scanJob->id . '/nuclei');
        File::ensureDirectoryExists($outputDirectory);
        $reportPath = $outputDirectory . DIRECTORY_SEPARATOR . 'nuclei-report.json';

        // Extract optional authenticated scan credentials (Form Login / Header / Cookie / Basic Auth)
        $authHeaders = [];
        if ($target && ! empty($target->metadata['auth'])) {
            $auth = $target->metadata['auth'];
            try {
                if (! empty($auth['resolved_cookie_value'])) {
                    $cookieVal = Crypt::decryptString($auth['resolved_cookie_value']);
                    $authHeaders[] = "Cookie: {$cookieVal}";
                } elseif ($auth['type'] === 'cookie' && ! empty($auth['cookie_value'])) {
                    $cookieVal = Crypt::decryptString($auth['cookie_value']);
                    $authHeaders[] = "Cookie: {$cookieVal}";
                } elseif ($auth['type'] === 'header' && ! empty($auth['header_value'])) {
                    $headerName = $auth['header_name'] ?? 'Authorization';
                    $headerVal = Crypt::decryptString($auth['header_value']);
                    $authHeaders[] = "{$headerName}: {$headerVal}";
                } elseif ($auth['type'] === 'basic' && ! empty($auth['username'])) {
                    $user = $auth['username'];
                    $pass = ! empty($auth['password']) ? Crypt::decryptString($auth['password']) : '';
                    $basicToken = base64_encode("{$user}:{$pass}");
                    $authHeaders[] = "Authorization: Basic {$basicToken}";
                }
            } catch (\Throwable $e) {
                // If decrypt fails, continue gracefully
            }
        }

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
            'secsys-nuclei-templates:/root/nuclei-templates:ro',
            '-v',
            $this->dockerStorageMountSource($outputDirectory) . ":{$containerOutputPath}:rw",
            $image,
            '-u',
            $targetUrl,
            '-json-export',
            "{$containerOutputPath}/nuclei-report.json",
        ];

        // Append custom authentication headers for deep authenticated scanning
        foreach ($authHeaders as $header) {
            $command[] = '-H';
            $command[] = $header;
        }

        $command = array_merge($command, [
            '-as',
            '-tags',
            'cve,vulnerabilities,misconfiguration,exposures,sqli,xss,ssrf,rce,lfi,idor,auth-bypass,default-login,ssl',
            '-rate-limit',
            '100',
            '-timeout',
            '10',
            '-retries',
            '1',
            '-duc',
            '-ni',
            '-nc',
            '-silent',
        ]);

        $process = new Process($command);
        $timeout = (int) config('secsys.engine_timeout_seconds', 600);
        $process->setTimeout($timeout);
        $process->setIdleTimeout(null);

        $startTime = microtime(true);

        try {
            $process->run();
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            $rawOutput = $process->getOutput();
            $errorOutput = $process->getErrorOutput();

            $reportContent = File::exists($reportPath) ? File::get($reportPath) : $rawOutput;
            $findings = $this->parseFindings($reportContent, $targetUrl);

            return new EngineExecutionResult(
                status: 'completed',
                exitCode: $process->getExitCode(),
                commandSpec: [
                    ...$commandSpec,
                    'scanner_execution' => true,
                    'container_image' => $image,
                    'command' => $command,
                ],
                runtimeMetrics: [
                    'duration_ms' => $durationMs,
                    'finding_count' => count($findings),
                    'stderr_present' => ! empty($errorOutput),
                ],
                rawOutput: $reportContent,
                failureReason: null,
                normalizedFindings: $findings,
            );
        } catch (ProcessTimedOutException $e) {
            return new EngineExecutionResult(
                status: 'failed',
                exitCode: null,
                commandSpec: $commandSpec,
                runtimeMetrics: ['duration_ms' => (int) config('secsys.engine_timeout_seconds') * 1000],
                rawOutput: null,
                failureReason: 'NUCLEI_PROCESS_TIMEOUT',
                normalizedFindings: [],
            );
        } catch (\Throwable $e) {
            return new EngineExecutionResult(
                status: 'failed',
                exitCode: $process->getExitCode() ?? 1,
                commandSpec: $commandSpec,
                runtimeMetrics: [],
                rawOutput: $process->getErrorOutput() ?: $e->getMessage(),
                failureReason: 'NUCLEI_PROCESS_FAILED: ' . $e->getMessage(),
                normalizedFindings: [],
            );
        }
    }

    public function parseFindings(string $output, string $targetUrl): array
    {
        if (empty(trim($output))) {
            return [];
        }

        $entries = [];

        // Try decoding as full JSON array or lines of JSON
        $decoded = json_decode($output, true);
        if (is_array($decoded)) {
            $entries = isset($decoded[0]) ? $decoded : [$decoded];
        } else {
            // Handle JSONL (one JSON object per line)
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || ! Str::startsWith($line, '{')) {
                    continue;
                }
                $item = json_decode($line, true);
                if (is_array($item)) {
                    $entries[] = $item;
                }
            }
        }

        $findings = [];
        foreach ($entries as $entry) {
            $info = $entry['info'] ?? [];
            $templateId = $entry['template-id'] ?? $entry['templateID'] ?? 'web-vuln';
            $title = $info['name'] ?? Str::headline($templateId);
            $rawSeverity = strtolower($info['severity'] ?? 'info');
            $severity = match ($rawSeverity) {
                'critical' => 'critical',
                'high' => 'high',
                'medium' => 'medium',
                'low' => 'low',
                default => 'informational',
            };

            $matchedAt = $entry['matched-at'] ?? $entry['matched'] ?? $entry['host'] ?? $targetUrl;
            $cve = $info['classification']['cve-id'] ?? null;
            $cwe = $info['classification']['cwe-id'] ?? null;
            $cvss = $info['classification']['cvss-score'] ?? null;
            $curlCommand = $entry['curl-command'] ?? null;
            $extracted = $entry['extracted-results'] ?? null;

            $evidenceStr = '';
            if (! empty($curlCommand)) {
                $evidenceStr .= "Reproduction Curl:\n" . $curlCommand . "\n\n";
            }
            if (! empty($extracted)) {
                $evidenceStr .= "Extracted Data: " . (is_array($extracted) ? implode(', ', $extracted) : $extracted) . "\n";
            }
            if (empty($evidenceStr)) {
                $evidenceStr = "Endpoint teridentifikasi: {$matchedAt}";
            }

            $findings[] = [
                'title' => $title,
                'rule_id' => $templateId,
                'severity_raw' => strtoupper($rawSeverity),
                'severity' => $severity,
                'confidence' => 0.85,
                'asset_type' => 'web_app',
                'asset_identifier' => $targetUrl,
                'file_path' => null,
                'line_start' => null,
                'line_end' => null,
                'http_method' => strtoupper($entry['type'] ?? 'HTTP'),
                'endpoint' => $matchedAt,
                'cwe' => is_array($cwe) ? implode(', ', $cwe) : $cwe,
                'owasp' => is_array($info['classification']['owasp-top10'] ?? null) ? implode(', ', $info['classification']['owasp-top10']) : null,
                'cve' => is_array($cve) ? implode(', ', $cve) : $cve,
                'cvss' => $cvss,
                'evidence_summary' => [
                    'matched_at' => $matchedAt,
                    'type' => $entry['type'] ?? 'http',
                    'remediation' => $info['remediation'] ?? null,
                    'description' => $info['description'] ?? null,
                ],
                'evidence' => trim($evidenceStr),
                'raw' => $entry,
            ];
        }

        return $findings;
    }

    private function binaryExists(string $binary): bool
    {
        $process = new Process([$binary, '--version']);
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful();
    }

    private function dockerStorageMountSource(string $path): string
    {
        $hostStorageRoot = config('secsys.docker_host_storage_root');

        if ($hostStorageRoot) {
            $relative = Str::after($path, storage_path());

            return rtrim($hostStorageRoot, '/\\').DIRECTORY_SEPARATOR.ltrim($relative, '/\\');
        }

        return $path;
    }
}
