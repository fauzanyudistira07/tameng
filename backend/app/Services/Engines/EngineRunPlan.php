<?php

namespace App\Services\Engines;

use App\Models\ScanJob;

class EngineRunPlan
{
    public function __construct(
        public readonly ScanJob $scanJob,
        public readonly array $engine,
        public readonly array $policyDecision,
    ) {}

    public function toCommandSpec(): array
    {
        return [
            'mode' => 'adapter_skeleton',
            'scanner_execution' => false,
            'engine_key' => $this->engine['key'],
            'external_binary' => $this->engine['external_binary'],
            'risk_level' => $this->engine['risk_level'],
            'required_input' => $this->engine['required_input'],
            'safety_controls' => $this->engine['safety_controls'],
            'execution_decision' => $this->policyDecision['decision'],
            'reason_code' => $this->policyDecision['reason_code'],
            'policy_decision_id' => $this->policyDecision['policy_decision']->id,
        ];
    }
}
