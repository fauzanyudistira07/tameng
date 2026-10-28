<?php

namespace App\Services\Engines;

use App\Services\Engines\Adapters\CheckovAdapter;
use App\Services\Engines\Adapters\CodeqlAdapter;
use App\Services\Engines\Adapters\DependencyCheckAdapter;
use App\Services\Engines\Adapters\GitleaksAdapter;
use App\Services\Engines\Adapters\GrypeAdapter;
use App\Services\Engines\Adapters\HadolintAdapter;
use App\Services\Engines\Adapters\KubescapeAdapter;
use App\Services\Engines\Adapters\MobSFAdapter;
use App\Services\Engines\Adapters\NiktoAdapter;
use App\Services\Engines\Adapters\NucleiAdapter;
use App\Services\Engines\Adapters\NullEngineAdapter;
use App\Services\Engines\Adapters\OsvAdapter;
use App\Services\Engines\Adapters\SemgrepAdapter;
use App\Services\Engines\Adapters\SonarQubeAdapter;
use App\Services\Engines\Adapters\SyftAdapter;
use App\Services\Engines\Adapters\TestSslAdapter;
use App\Services\Engines\Adapters\TrivyAdapter;
use App\Services\Engines\Adapters\TrufflehogAdapter;
use App\Services\Engines\Adapters\WapitiAdapter;
use App\Services\Engines\Adapters\WuppieFuzzAdapter;
use App\Services\Engines\Adapters\ZapAdapter;
use App\Services\Engines\Contracts\EngineAdapter;

class EngineAdapterResolver
{
    public function __construct(
        private readonly GitleaksAdapter $gitleaksAdapter,
        private readonly SemgrepAdapter $semgrepAdapter,
        private readonly TrivyAdapter $trivyAdapter,
        private readonly OsvAdapter $osvAdapter,
        private readonly HadolintAdapter $hadolintAdapter,
        private readonly CheckovAdapter $checkovAdapter,
        private readonly SyftAdapter $syftAdapter,
        private readonly GrypeAdapter $grypeAdapter,
        private readonly MobSFAdapter $mobsfAdapter,
        private readonly NucleiAdapter $nucleiAdapter,
        private readonly ZapAdapter $zapAdapter,
        private readonly TestSslAdapter $testSslAdapter,
        private readonly NiktoAdapter $niktoAdapter,
        private readonly CodeqlAdapter $codeqlAdapter,
        private readonly SonarQubeAdapter $sonarQubeAdapter,
        private readonly TrufflehogAdapter $trufflehogAdapter,
        private readonly DependencyCheckAdapter $dependencyCheckAdapter,
        private readonly KubescapeAdapter $kubescapeAdapter,
        private readonly WapitiAdapter $wapitiAdapter,
        private readonly WuppieFuzzAdapter $wuppieFuzzAdapter,
    ) {}

    public function resolve(string $engineKey): EngineAdapter
    {
        return match ($engineKey) {
            'semgrep' => $this->semgrepAdapter,
            'gitleaks' => $this->gitleaksAdapter,
            'trivy' => $this->trivyAdapter,
            'osv' => $this->osvAdapter,
            'hadolint' => $this->hadolintAdapter,
            'checkov' => $this->checkovAdapter,
            'syft' => $this->syftAdapter,
            'grype' => $this->grypeAdapter,
            'mobsf' => $this->mobsfAdapter,
            'nuclei' => $this->nucleiAdapter,
            'zap' => $this->zapAdapter,
            'testssl' => $this->testSslAdapter,
            'nikto' => $this->niktoAdapter,
            'codeql' => $this->codeqlAdapter,
            'sonarqube' => $this->sonarQubeAdapter,
            'trufflehog' => $this->trufflehogAdapter,
            'dependency_check' => $this->dependencyCheckAdapter,
            'kubescape' => $this->kubescapeAdapter,
            'wapiti' => $this->wapitiAdapter,
            'wuppiefuzz' => $this->wuppieFuzzAdapter,
            default => new NullEngineAdapter($engineKey),
        };
    }
}
