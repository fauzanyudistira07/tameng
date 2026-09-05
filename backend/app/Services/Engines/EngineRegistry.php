<?php

namespace App\Services\Engines;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EngineRegistry
{
    public function all(): Collection
    {
        return collect($this->definitions())->map(fn (array $definition): array => [
            ...$definition,
            'status' => $definition['adapter'] === 'implemented' ? 'adapter_ready' : 'adapter_skeleton',
            'execution_mode' => config('secsys.real_engines_enabled')
                ? 'guarded_real_execution_enabled'
                : 'disabled_until_configured',
            'runtime' => config('secsys.engine_runtime'),
            'scanner_execution' => $definition['adapter'] === 'implemented' && config('secsys.real_engines_enabled'),
        ]);
    }

    public function get(string $engineKey): array
    {
        $engine = $this->all()->firstWhere('key', $engineKey);

        if (! $engine) {
            throw ValidationException::withMessages([
                'engine_key' => ["Engine {$engineKey} is not registered."],
            ]);
        }

        return $engine;
    }

    public function keys(): array
    {
        return $this->all()->pluck('key')->values()->all();
    }

    public function allowedForTargetType(string $targetType): Collection
    {
        return $this->all()
            ->filter(fn (array $engine): bool => in_array($targetType, $engine['target_types'], true))
            ->values();
    }

    private function definitions(): array
    {
        return [
            [
                'key' => 'semgrep',
                'name' => 'Semgrep',
                'category' => 'sast',
                'target_types' => ['repository'],
                'risk_level' => 'passive',
                'external_binary' => 'semgrep',
                'adapter' => 'implemented',
                'required_input' => ['verified_repository', 'working_copy'],
                'produces' => ['code_findings'],
                'safety_controls' => [
                    'network_disabled',
                    'read_only_working_copy',
                    'rule_allowlist',
                    'timeout_required',
                ],
            ],
            [
                'key' => 'gitleaks',
                'name' => 'Gitleaks',
                'category' => 'secret_scanning',
                'target_types' => ['repository'],
                'risk_level' => 'passive',
                'external_binary' => 'gitleaks',
                'adapter' => 'implemented',
                'required_input' => ['verified_repository', 'working_copy'],
                'produces' => ['secret_findings'],
                'safety_controls' => [
                    'network_disabled',
                    'read_only_working_copy',
                    'redact_secret_values',
                    'timeout_required',
                ],
            ],
            [
                'key' => 'trivy',
                'name' => 'Trivy',
                'category' => 'dependency_scanning',
                'target_types' => ['repository', 'container'],
                'risk_level' => 'passive',
                'external_binary' => 'trivy',
                'adapter' => 'implemented',
                'required_input' => ['verified_repository_or_image', 'working_copy_or_image_ref'],
                'produces' => ['dependency_findings', 'container_findings'],
                'safety_controls' => [
                    'network_limited_to_vuln_db',
                    'read_only_input',
                    'timeout_required',
                ],
            ],
            [
                'key' => 'osv',
                'name' => 'OSV Scanner',
                'category' => 'dependency_scanning',
                'target_types' => ['repository'],
                'risk_level' => 'passive',
                'external_binary' => 'osv-scanner',
                'adapter' => 'implemented',
                'required_input' => ['verified_repository', 'lockfiles'],
                'produces' => ['dependency_findings'],
                'safety_controls' => [
                    'network_limited_to_osv_api',
                    'read_only_working_copy',
                    'timeout_required',
                ],
            ],
            [
                'key' => 'zap',
                'name' => 'OWASP ZAP',
                'category' => 'dast',
                'target_types' => ['web', 'api'],
                'risk_level' => 'controlled_active',
                'external_binary' => 'zap-baseline.py',
                'adapter' => 'implemented',
                'required_input' => ['verified_target', 'allow_scope', 'egress_proxy'],
                'produces' => ['web_findings', 'api_findings'],
                'safety_controls' => [
                    'authorization_required',
                    'scope_enforced',
                    'rate_limit_required',
                    'passive_or_baseline_mode_first',
                    'timeout_required',
                ],
            ],
            [
                'key' => 'testssl',
                'name' => 'TestSSL.sh',
                'category' => 'tls_audit',
                'target_types' => ['web', 'api', 'tls'],
                'risk_level' => 'passive',
                'external_binary' => 'testssl.sh',
                'adapter' => 'implemented',
                'required_input' => ['verified_target'],
                'produces' => ['tls_findings', 'web_findings'],
                'safety_controls' => [
                    'authorization_required',
                    'timeout_required',
                ],
            ],
            [
                'key' => 'nikto',
                'name' => 'Nikto Web Server Scanner',
                'category' => 'web_server_audit',
                'target_types' => ['web', 'api'],
                'risk_level' => 'controlled_active',
                'external_binary' => 'nikto',
                'adapter' => 'implemented',
                'required_input' => ['verified_target', 'allow_scope'],
                'produces' => ['web_findings', 'server_findings'],
                'safety_controls' => [
                    'authorization_required',
                    'scope_enforced',
                    'timeout_required',
                ],
            ],
            [
                'key' => 'nuclei',
                'name' => 'Nuclei',
                'category' => 'template_scanning',
                'target_types' => ['web', 'api'],
                'risk_level' => 'controlled_active',
                'external_binary' => 'nuclei',
                'adapter' => 'implemented',
                'required_input' => ['verified_target', 'allow_scope', 'template_allowlist'],
                'produces' => ['web_findings', 'api_findings'],
                'safety_controls' => [
                    'authorization_required',
                    'scope_enforced',
                    'safe_template_allowlist',
                    'rate_limit_required',
                    'timeout_required',
                ],
            ],
            [
                'key' => 'hadolint',
                'name' => 'Hadolint',
                'category' => 'container_linting',
                'target_types' => ['repository', 'container'],
                'risk_level' => 'passive',
                'external_binary' => 'hadolint',
                'adapter' => 'implemented',
                'required_input' => ['verified_repository', 'dockerfile'],
                'produces' => ['container_findings', 'code_findings'],
                'safety_controls' => [
                    'network_disabled',
                    'read_only_working_copy',
                    'timeout_required',
                ],
            ],
            [
                'key' => 'checkov',
                'name' => 'Checkov',
                'category' => 'iac_scanning',
                'target_types' => ['repository'],
                'risk_level' => 'passive',
                'external_binary' => 'checkov',
                'adapter' => 'implemented',
                'required_input' => ['verified_repository', 'working_copy'],
                'produces' => ['iac_findings', 'code_findings'],
                'safety_controls' => [
                    'read_only_working_copy',
                    'timeout_required',
                ],
            ],
            [
                'key' => 'syft',
                'name' => 'Anchore Syft',
                'category' => 'sbom_generation',
                'target_types' => ['repository', 'container'],
                'risk_level' => 'passive',
                'external_binary' => 'syft',
                'adapter' => 'implemented',
                'required_input' => ['verified_repository', 'working_copy'],
                'produces' => ['sbom_inventory', 'compliance_findings'],
                'safety_controls' => [
                    'read_only_working_copy',
                    'timeout_required',
                ],
            ],
            [
                'key' => 'grype',
                'name' => 'Anchore Grype',
                'category' => 'container_security',
                'target_types' => ['repository', 'container'],
                'risk_level' => 'passive',
                'external_binary' => 'grype',
                'adapter' => 'implemented',
                'required_input' => ['verified_repository_or_image', 'working_copy_or_image_ref'],
                'produces' => ['container_findings', 'dependency_findings'],
                'safety_controls' => [
                    'read_only_working_copy',
                    'timeout_required',
                ],
            ],
            [
                'key' => 'mobsf',
                'name' => 'MobSF',
                'category' => 'mobile_scanning',
                'target_types' => ['mobile'],
                'risk_level' => 'passive',
                'external_binary' => 'mobsfscan',
                'adapter' => 'planned',
                'required_input' => ['uploaded_mobile_artifact'],
                'produces' => ['mobile_findings'],
                'safety_controls' => [
                    'artifact_quarantine',
                    'read_only_analysis',
                    'no_device_interaction_by_default',
                    'timeout_required',
                ],
            ],
        ];
    }
}
