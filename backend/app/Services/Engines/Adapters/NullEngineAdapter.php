<?php

namespace App\Services\Engines\Adapters;

use App\Services\Engines\Contracts\EngineAdapter;
use App\Services\Engines\EngineRunPlan;
use App\Services\Engines\Support\EngineExecutionResult;

class NullEngineAdapter implements EngineAdapter
{
    public function __construct(private readonly string $engineKey) {}

    public function key(): string
    {
        return $this->engineKey;
    }

    public function execute(EngineRunPlan $plan): EngineExecutionResult
    {
        return EngineExecutionResult::skipped(
            commandSpec: [
                ...$plan->toCommandSpec(),
                'mode' => 'adapter_missing',
                'scanner_execution' => false,
            ],
            reason: 'ENGINE_ADAPTER_NOT_IMPLEMENTED',
        );
    }
}
