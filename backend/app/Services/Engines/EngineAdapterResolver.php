<?php

namespace App\Services\Engines;

use App\Services\Engines\Adapters\CheckovAdapter;
use App\Services\Engines\Adapters\GitleaksAdapter;
use App\Services\Engines\Adapters\GrypeAdapter;
use App\Services\Engines\Adapters\HadolintAdapter;
use App\Services\Engines\Adapters\NiktoAdapter;
use App\Services\Engines\Adapters\NucleiAdapter;
use App\Services\Engines\Adapters\NullEngineAdapter;
use App\Services\Engines\Adapters\OsvAdapter;
use App\Services\Engines\Adapters\SemgrepAdapter;
use App\Services\Engines\Adapters\SyftAdapter;
use App\Services\Engines\Adapters\TestSslAdapter;
use App\Services\Engines\Adapters\TrivyAdapter;
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
        private readonly NucleiAdapter $nucleiAdapter,
        private readonly ZapAdapter $zapAdapter,
        private readonly TestSslAdapter $testSslAdapter,
        private readonly NiktoAdapter $niktoAdapter,
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
            'nuclei' => $this->nucleiAdapter,
            'zap' => $this->zapAdapter,
            'testssl' => $this->testSslAdapter,
            'nikto' => $this->niktoAdapter,
            default => new NullEngineAdapter($engineKey),
        };
    }
}
