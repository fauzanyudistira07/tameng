<?php

namespace Database\Seeders;

use App\Models\EngineVersion;
use App\Models\ScanProfileEngine;
use App\Models\SecurityEngine;
use Illuminate\Database\Seeder;

class SecurityEngineSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $readyEngines = ['semgrep', 'gitleaks', 'trivy', 'osv', 'zap', 'nuclei', 'hadolint', 'checkov', 'syft', 'grype'];

        $engines = [
            // 1. SOURCE_CODE
            [
                'code' => 'semgrep',
                'name' => 'Semgrep SAST',
                'domain' => 'SOURCE_CODE',
                'category' => 'sast',
                'version' => '1.74.0',
                'adapter_version' => '1.2.0',
                'container_image' => 'semgrep/semgrep:1.74.0',
                'resource_class' => 'MEDIUM',
                'supported_targets' => ['repository'],
                'default_timeout' => 300,
                'cpu_limit' => 2.0,
                'memory_limit_mb' => 2048,
                'description' => 'Fast, pattern-based lightweight static analysis engine for multi-language source code vulnerability detection.',
            ],
            [
                'code' => 'codeql',
                'name' => 'GitHub CodeQL',
                'domain' => 'SOURCE_CODE',
                'category' => 'sast',
                'version' => '2.16.5',
                'adapter_version' => '1.0.0',
                'container_image' => 'mcr.microsoft.com/cstsectools/codeql-container:latest',
                'resource_class' => 'HEAVY',
                'supported_targets' => ['repository'],
                'default_timeout' => 900,
                'cpu_limit' => 4.0,
                'memory_limit_mb' => 8192,
                'description' => 'Deep semantic code analysis engine querying source code as data to detect data-flow and taint vulnerabilities.',
            ],
            [
                'code' => 'sonarqube',
                'name' => 'SonarQube Scanner',
                'domain' => 'SOURCE_CODE',
                'category' => 'sast',
                'version' => '5.0.1',
                'adapter_version' => '1.0.0',
                'container_image' => 'sonarsource/sonar-scanner-cli:latest',
                'resource_class' => 'HEAVY',
                'supported_targets' => ['repository'],
                'default_timeout' => 600,
                'cpu_limit' => 2.5,
                'memory_limit_mb' => 4096,
                'description' => 'Security hotspots, code smells, bugs, and comprehensive quality gate analyzer for software codebases.',
            ],

            // 2. SECRET
            [
                'code' => 'gitleaks',
                'name' => 'Gitleaks Secrets',
                'domain' => 'SECRET',
                'category' => 'secret_leak',
                'version' => '8.28.0',
                'adapter_version' => '1.2.0',
                'container_image' => 'zricethezav/gitleaks:v8.28.0',
                'resource_class' => 'LIGHT',
                'supported_targets' => ['repository'],
                'default_timeout' => 180,
                'cpu_limit' => 1.0,
                'memory_limit_mb' => 1024,
                'description' => 'High-performance secrets and credential scanner in git commits and working trees.',
            ],
            [
                'code' => 'trufflehog',
                'name' => 'TruffleHog v3',
                'domain' => 'SECRET',
                'category' => 'secret_leak',
                'version' => '3.68.0',
                'adapter_version' => '1.0.0',
                'container_image' => 'trufflesecurity/trufflehog:3.68.0',
                'resource_class' => 'LIGHT',
                'supported_targets' => ['repository'],
                'default_timeout' => 240,
                'cpu_limit' => 1.0,
                'memory_limit_mb' => 1024,
                'description' => 'High-entropy secret detection with active verification against hundreds of cloud and SaaS credential providers.',
            ],

            // 3. DEPENDENCY (SCA)
            [
                'code' => 'trivy',
                'name' => 'Aqua Trivy',
                'domain' => 'DEPENDENCY',
                'category' => 'sca_cve',
                'version' => '0.49.1',
                'adapter_version' => '1.2.0',
                'container_image' => 'aquasec/trivy:latest',
                'resource_class' => 'MEDIUM',
                'supported_targets' => ['repository', 'container_image'],
                'default_timeout' => 300,
                'cpu_limit' => 2.0,
                'memory_limit_mb' => 2048,
                'description' => 'Comprehensive vulnerability and misconfiguration scanner for lockfiles, packages, and OS dependencies.',
            ],
            [
                'code' => 'osv',
                'name' => 'Google OSV-Scanner',
                'domain' => 'DEPENDENCY',
                'category' => 'sca_cve',
                'version' => '1.6.2',
                'adapter_version' => '1.2.0',
                'container_image' => 'ghcr.io/google/osv-scanner:latest',
                'resource_class' => 'LIGHT',
                'supported_targets' => ['repository'],
                'default_timeout' => 180,
                'cpu_limit' => 1.0,
                'memory_limit_mb' => 1024,
                'description' => 'Vulnerability scanner matching open source dependencies against the Open Source Vulnerability (OSV) database.',
            ],
            [
                'code' => 'dependency_check',
                'name' => 'OWASP Dependency-Check',
                'domain' => 'DEPENDENCY',
                'category' => 'sca_cve',
                'version' => '9.0.9',
                'adapter_version' => '1.0.0',
                'container_image' => 'owasp/dependency-check:9.0.9',
                'resource_class' => 'MEDIUM',
                'supported_targets' => ['repository'],
                'default_timeout' => 600,
                'cpu_limit' => 2.0,
                'memory_limit_mb' => 3072,
                'description' => 'Software Composition Analysis tool identifying project dependencies and checking against NIST National Vulnerability Database (NVD).',
            ],

            // 4. SBOM
            [
                'code' => 'syft',
                'name' => 'Anchore Syft',
                'domain' => 'SBOM',
                'category' => 'sbom',
                'version' => '0.105.0',
                'adapter_version' => '1.0.0',
                'container_image' => 'anchore/syft:v0.105.0',
                'resource_class' => 'LIGHT',
                'supported_targets' => ['repository', 'container_image'],
                'default_timeout' => 120,
                'cpu_limit' => 1.0,
                'memory_limit_mb' => 1024,
                'description' => 'CLI tool and library for generating a Software Bill of Materials (SBOM) in SPDX and CycloneDX standard formats.',
            ],

            // 5. CONTAINER
            [
                'code' => 'grype',
                'name' => 'Anchore Grype',
                'domain' => 'CONTAINER',
                'category' => 'container',
                'version' => '0.74.0',
                'adapter_version' => '1.0.0',
                'container_image' => 'anchore/grype:v0.74.0',
                'resource_class' => 'MEDIUM',
                'supported_targets' => ['repository', 'container_image'],
                'default_timeout' => 240,
                'cpu_limit' => 1.5,
                'memory_limit_mb' => 2048,
                'description' => 'Vulnerability scanner specifically designed for container images and filesystems.',
            ],
            [
                'code' => 'hadolint',
                'name' => 'Hadolint Dockerfile Linter',
                'domain' => 'CONTAINER',
                'category' => 'container',
                'version' => '2.12.0',
                'adapter_version' => '1.0.0',
                'container_image' => 'hadolint/hadolint:v2.12.0',
                'resource_class' => 'LIGHT',
                'supported_targets' => ['repository'],
                'default_timeout' => 60,
                'cpu_limit' => 0.5,
                'memory_limit_mb' => 512,
                'description' => 'Smarter Dockerfile linter that helps you build best practice Docker images by parsing AST and running ShellCheck.',
            ],

            // 6. IAC
            [
                'code' => 'checkov',
                'name' => 'Bridgecrew Checkov',
                'domain' => 'IAC',
                'category' => 'iac',
                'version' => '3.2.35',
                'adapter_version' => '1.0.0',
                'container_image' => 'bridgecrew/checkov:3.2.35',
                'resource_class' => 'MEDIUM',
                'supported_targets' => ['repository'],
                'default_timeout' => 300,
                'cpu_limit' => 2.0,
                'memory_limit_mb' => 2048,
                'description' => 'Static code analysis tool for infrastructure as code (IaC) covering Terraform, CloudFormation, Kubernetes, and Helm.',
            ],
            [
                'code' => 'kubescape',
                'name' => 'ARMO Kubescape',
                'domain' => 'IAC',
                'category' => 'iac',
                'version' => '3.0.4',
                'adapter_version' => '1.0.0',
                'container_image' => 'kubescape/kubescape:v3.0.4',
                'resource_class' => 'MEDIUM',
                'supported_targets' => ['repository'],
                'default_timeout' => 300,
                'cpu_limit' => 2.0,
                'memory_limit_mb' => 2048,
                'description' => 'Kubernetes security platform providing risk analysis, security compliance (NSA-CISA, MITRE), and RBAC visualizer.',
            ],

            // 7. WEB (DAST)
            [
                'code' => 'zap',
                'name' => 'OWASP ZAP',
                'domain' => 'WEB',
                'category' => 'dast',
                'version' => '2.14.0',
                'adapter_version' => '1.2.0',
                'container_image' => 'zaproxy/zap-stable:2.14.0',
                'resource_class' => 'HEAVY',
                'supported_targets' => ['web_target'],
                'default_timeout' => 1200,
                'cpu_limit' => 3.0,
                'memory_limit_mb' => 4096,
                'description' => 'Dynamic Application Security Testing (DAST) proxy scanner assessing live web applications and REST endpoints.',
            ],
            [
                'code' => 'nuclei',
                'name' => 'ProjectDiscovery Nuclei',
                'domain' => 'WEB',
                'category' => 'dast',
                'version' => '3.1.8',
                'adapter_version' => '1.2.0',
                'container_image' => 'projectdiscovery/nuclei:v3.1.8',
                'resource_class' => 'MEDIUM',
                'supported_targets' => ['web_target'],
                'default_timeout' => 450,
                'cpu_limit' => 2.0,
                'memory_limit_mb' => 2048,
                'description' => 'Fast and customizable vulnerability scanner based on simple YAML DSL templates for CVE and exposure verification.',
            ],
            [
                'code' => 'nikto',
                'name' => 'Sullo Nikto',
                'domain' => 'WEB',
                'category' => 'dast',
                'version' => '2.1.6',
                'adapter_version' => '1.0.0',
                'container_image' => 'sullo/nikto:2.1.6',
                'resource_class' => 'MEDIUM',
                'supported_targets' => ['web_target'],
                'default_timeout' => 600,
                'cpu_limit' => 1.5,
                'memory_limit_mb' => 1536,
                'description' => 'Open source web server scanner performing comprehensive tests against web servers for multiple dangerous files/CGIs and server misconfigurations.',
            ],
            [
                'code' => 'wapiti',
                'name' => 'Wapiti Web Scanner',
                'domain' => 'WEB',
                'category' => 'dast',
                'version' => '3.1.8',
                'adapter_version' => '1.0.0',
                'container_image' => 'wapiti/wapiti:3.1.8',
                'resource_class' => 'MEDIUM',
                'supported_targets' => ['web_target'],
                'default_timeout' => 600,
                'cpu_limit' => 2.0,
                'memory_limit_mb' => 2048,
                'description' => 'Black-box web-application vulnerability scanner performing "black-box" scans without studying source code.',
            ],

            // 8. API
            [
                'code' => 'wuppiefuzz',
                'name' => 'WuppieFuzz REST API',
                'domain' => 'API',
                'category' => 'api',
                'version' => '1.0.0',
                'adapter_version' => '1.0.0',
                'container_image' => 'secsys/wuppiefuzz:latest',
                'resource_class' => 'MEDIUM',
                'supported_targets' => ['web_target'],
                'default_timeout' => 600,
                'cpu_limit' => 2.0,
                'memory_limit_mb' => 2048,
                'description' => 'Deterministic OpenAPI / Swagger parameter fuzzer and boundary condition security analyzer.',
            ],

            // 9. MOBILE
            [
                'code' => 'mobsf',
                'name' => 'Mobile Security Framework (MobSF)',
                'domain' => 'MOBILE',
                'category' => 'mobile',
                'version' => '3.9.0',
                'adapter_version' => '1.0.0',
                'container_image' => 'opensecurity/mobile-security-framework-mobsf:latest',
                'resource_class' => 'HEAVY',
                'supported_targets' => ['mobile_app', 'repository'],
                'default_timeout' => 900,
                'cpu_limit' => 4.0,
                'memory_limit_mb' => 6144,
                'description' => 'Automated, all-in-one mobile application (Android/iOS) pen-testing, malware analysis and security assessment framework.',
            ],

            // 10. TLS
            [
                'code' => 'testssl',
                'name' => 'testssl.sh TLS Analyzer',
                'domain' => 'TLS',
                'category' => 'tls',
                'version' => '3.0',
                'adapter_version' => '1.0.0',
                'container_image' => 'drwetter/testssl.sh:3.0',
                'resource_class' => 'MEDIUM',
                'supported_targets' => ['web_target'],
                'default_timeout' => 300,
                'cpu_limit' => 1.5,
                'memory_limit_mb' => 1536,
                'description' => 'Free command line tool which checks a server\'s service on any port for the support of TLS/SSL ciphers, protocols as well as recent cryptographic flaws.',
            ],
        ];

        foreach ($engines as $data) {
            $isReady = in_array($data['code'], $readyEngines, true);
            $data['enabled'] = $isReady;
            $data['status'] = $isReady ? 'AVAILABLE' : 'DISABLED';

            $engine = SecurityEngine::updateOrCreate(
                ['code' => $data['code']],
                $data
            );

            EngineVersion::updateOrCreate(
                [
                    'security_engine_id' => $engine->id,
                    'version' => $engine->version,
                ],
                [
                    'container_image' => $engine->container_image,
                    'adapter_version' => $engine->adapter_version,
                    'is_active' => true,
                    'changelog' => 'Initial release baseline for TAMENG SecSys Engine Layer.',
                ]
            );
        }

        // Seed Scan Profile Engine Mappings
        $profiles = [
            'SOURCE_BASIC' => ['semgrep', 'gitleaks', 'trivy', 'osv'],
            'SOURCE_ADVANCED' => ['semgrep', 'codeql', 'sonarqube', 'gitleaks', 'trufflehog', 'trivy', 'osv', 'dependency_check', 'syft', 'hadolint'],
            'WEB_BASIC' => ['zap', 'nuclei', 'testssl'],
            'WEB_ADVANCED' => ['zap', 'nuclei', 'nikto', 'wapiti', 'testssl'],
            'CONTAINER_IAC' => ['trivy', 'grype', 'hadolint', 'checkov', 'kubescape', 'syft'],
            'FULL_DEFENSE' => ['semgrep', 'codeql', 'sonarqube', 'gitleaks', 'trufflehog', 'trivy', 'osv', 'dependency_check', 'grype', 'syft', 'hadolint', 'checkov', 'kubescape', 'zap', 'nuclei', 'nikto', 'wapiti', 'wuppiefuzz', 'mobsf', 'testssl'],
        ];

        foreach ($profiles as $profileCode => $engineCodes) {
            $order = 1;
            foreach ($engineCodes as $code) {
                $engine = SecurityEngine::where('code', $code)->first();
                if ($engine) {
                    ScanProfileEngine::updateOrCreate(
                        [
                            'profile_code' => $profileCode,
                            'security_engine_id' => $engine->id,
                        ],
                        [
                            'is_required' => true,
                            'execution_order' => $order++,
                        ]
                    );
                }
            }
        }
    }
}
