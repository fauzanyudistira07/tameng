<?php

namespace App\Services\Engines\Support;

use App\Models\SecurityEngine;
use Illuminate\Support\Facades\File;

class DeterministicEngineSelector
{
    /**
     * Deterministically select the list of active engine codes to run for a target.
     */
    public function selectEngines(string $targetType, ?string $workspacePath = null, ?string $profileCode = null): array
    {
        // 1. If explicit scan profile specified (e.g. SOURCE_BASIC, WEB_ADVANCED)
        if ($profileCode) {
            $mapped = SecurityEngine::active()
                ->whereHas('profileEngines', fn ($q) => $q->where('profile_code', strtoupper($profileCode)))
                ->orderBy('code')
                ->pluck('code')
                ->all();

            if (! empty($mapped)) {
                return $mapped;
            }
        }

        // 2. Container Target selection (Docker Image / Registry Tag)
        if (in_array($targetType, ['container', 'container_image', 'docker_image'], true)) {
            return ['trivy', 'grype', 'syft'];
        }

        // 3. Web & API Target selection
        if (in_array($targetType, ['web', 'api', 'web_target', 'web_app'], true)) {
            $engines = SecurityEngine::active()
                ->whereIn('code', ['nuclei', 'zap', 'testssl', 'nikto'])
                ->pluck('code')
                ->all();

            return ! empty($engines) ? $engines : ['nuclei', 'testssl', 'nikto'];
        }

        // 3. Mobile App selection
        if (in_array($targetType, ['mobile', 'mobile_app', 'apk', 'ipa', 'android', 'ios'], true)) {
            return ['mobsf', 'semgrep', 'gitleaks', 'trivy'];
        }

        // 4. Repository Target: Inspect workspace markers
        $engines = ['semgrep', 'gitleaks', 'trivy', 'osv']; // Core baseline

        if ($workspacePath && File::isDirectory($workspacePath)) {
            // Check for Mobile Application Markers (Android / iOS / Flutter / React Native)
            $hasMobile = File::exists($workspacePath.'/AndroidManifest.xml')
                || File::exists($workspacePath.'/build.gradle')
                || File::exists($workspacePath.'/build.gradle.kts')
                || File::exists($workspacePath.'/Podfile')
                || File::isDirectory($workspacePath.'/android')
                || File::isDirectory($workspacePath.'/ios')
                || ! empty(File::glob($workspacePath.'/*.swift'))
                || ! empty(File::glob($workspacePath.'/*.kt'));

            if ($hasMobile) {
                $engines[] = 'mobsf';
            }

            // Check for Dockerfile
            if (File::exists($workspacePath.DIRECTORY_SEPARATOR.'Dockerfile') || ! empty(File::glob($workspacePath.'/*Dockerfile*'))) {
                $engines[] = 'hadolint';
                $engines[] = 'grype';
            }

            // Check for Package/Dependency Lockfiles
            $hasLockfiles = File::exists($workspacePath.'/composer.lock')
                || File::exists($workspacePath.'/package-lock.json')
                || File::exists($workspacePath.'/yarn.lock')
                || File::exists($workspacePath.'/pnpm-lock.yaml')
                || File::exists($workspacePath.'/pom.xml')
                || File::exists($workspacePath.'/go.sum')
                || File::exists($workspacePath.'/requirements.txt');

            if ($hasLockfiles) {
                $engines[] = 'dependency_check';
                $engines[] = 'syft';
            }

            // Check for IaC / Terraform / Kubernetes
            $hasIac = ! empty(File::glob($workspacePath.'/*.tf'))
                || ! empty(File::glob($workspacePath.'/*.tfvars'))
                || File::isDirectory($workspacePath.'/terraform')
                || File::isDirectory($workspacePath.'/k8s')
                || File::isDirectory($workspacePath.'/helm');

            if ($hasIac) {
                $engines[] = 'checkov';
                $engines[] = 'kubescape';
            }
        }

        // Filter against enabled engines in database
        $activeCodes = SecurityEngine::active()->pluck('code')->all();

        $selected = array_values(array_unique(array_intersect($engines, $activeCodes)));

        return ! empty($selected) ? $selected : ['semgrep', 'gitleaks', 'trivy', 'osv'];
    }
}
