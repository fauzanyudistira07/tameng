<?php

namespace App\Services\Engines\Contracts;

use App\Services\Engines\EngineRunPlan;
use App\Services\Engines\Support\EngineExecutionResult;

interface EngineAdapter
{
    public function key(): string;

    public function execute(EngineRunPlan $plan): EngineExecutionResult;
}
