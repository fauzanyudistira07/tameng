<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $roles = [
            ['name' => 'super_admin', 'display_name' => 'Super Admin', 'description' => 'Full system administration.'],
            ['name' => 'security_admin', 'display_name' => 'Security Admin', 'description' => 'Manage projects, targets, scopes, authorization, and scans.'],
            ['name' => 'security_analyst', 'display_name' => 'Security Analyst', 'description' => 'Review findings, AI analysis, and reports.'],
            ['name' => 'developer', 'display_name' => 'Developer', 'description' => 'View assigned project findings and remediation guidance.'],
            ['name' => 'auditor', 'display_name' => 'Auditor', 'description' => 'Read-only access to reports and audit logs.'],
            ['name' => 'viewer', 'display_name' => 'Viewer', 'description' => 'Read-only dashboard access.'],
        ];

        DB::table('roles')->upsert($roles, ['name'], ['display_name', 'description']);

        $superAdminRoleId = DB::table('roles')->where('name', 'super_admin')->value('id');

        User::query()->updateOrCreate([
            'email' => 'admin@secsys.local',
        ], [
            'role_id' => $superAdminRoleId,
            'name' => 'System Admin',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        $profiles = [
            [
                'key' => 'source_code_scan',
                'name' => 'Source Code Scan',
                'description' => 'SAST, secret, dependency, and filesystem checks for verified repositories.',
                'allowed_target_types' => json_encode(['repository']),
                'engine_keys' => json_encode(['semgrep', 'gitleaks', 'trivy', 'osv', 'syft', 'hadolint', 'checkov']),
                'policy' => json_encode(['network' => 'disabled', 'requires_repository_verification' => true]),
                'active_testing' => false,
                'is_active' => true,
            ],
            [
                'key' => 'web_safe_scan',
                'name' => 'Web Safe Scan',
                'description' => 'Passive/spider web assessment with safe Nuclei template allowlist.',
                'allowed_target_types' => json_encode(['web']),
                'engine_keys' => json_encode(['zap', 'nuclei']),
                'policy' => json_encode(['network' => 'egress_proxy_required', 'template_mode' => 'allowlist']),
                'active_testing' => true,
                'is_active' => true,
            ],
            [
                'key' => 'api_safe_scan',
                'name' => 'API Safe Scan',
                'description' => 'OpenAPI/API scan with strict scope and egress policy.',
                'allowed_target_types' => json_encode(['api']),
                'engine_keys' => json_encode(['zap', 'nuclei']),
                'policy' => json_encode(['network' => 'egress_proxy_required', 'openapi_import' => true]),
                'active_testing' => true,
                'is_active' => true,
            ],
            [
                'key' => 'full_mvp_assessment',
                'name' => 'Full MVP Assessment',
                'description' => 'Repository, dependency, web/API safe assessment using all MVP engines.',
                'allowed_target_types' => json_encode(['repository', 'web', 'api']),
                'engine_keys' => json_encode(['semgrep', 'gitleaks', 'trivy', 'osv', 'zap', 'nuclei']),
                'policy' => json_encode(['network' => 'egress_proxy_required', 'requires_authorization_snapshot' => true]),
                'active_testing' => true,
                'is_active' => true,
            ],
            [
                'key' => 'container_security_scan',
                'name' => 'Container & Docker Security Scan',
                'description' => 'Container image vulnerability, CVE, OS packages, and SBOM inventory analysis.',
                'allowed_target_types' => json_encode(['repository', 'container']),
                'engine_keys' => json_encode(['trivy', 'grype', 'hadolint', 'syft']),
                'policy' => json_encode(['network' => 'disabled', 'requires_image_or_dockerfile' => true]),
                'active_testing' => false,
                'is_active' => true,
            ],
        ];

        DB::table('scan_profiles')->upsert($profiles, ['key'], [
            'name',
            'description',
            'allowed_target_types',
            'engine_keys',
            'policy',
            'active_testing',
            'is_active',
        ]);
    }
}
