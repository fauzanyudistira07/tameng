<?php

namespace App\Services\Engines\Contracts;

use App\Services\Engines\EngineRunPlan;
use App\Services\Engines\Support\EngineExecutionResult;

/**
 * Universal Contract for all Security Engine Adapters in TAMENG SecSys.
 * Enforces a strict 10-step execution and normalization lifecycle.
 */
interface UniversalEngineAdapterContract
{
    /**
     * Unique identifier key for this engine (e.g. 'semgrep', 'codeql', 'trivy', 'zap').
     */
    public function key(): string;

    /**
     * Check if this engine supports the specified target type.
     */
    public function supports(string $targetType): bool;

    /**
     * Validate whether the job has valid inputs, workspaces, or target URLs for this engine.
     */
    public function validate(array $jobPayload): bool;

    /**
     * Prepare temporary output directories, SARIF/JSON file paths, or target configs.
     */
    public function prepare(EngineRunPlan $plan): void;

    /**
     * Build execution command specification and container resource parameters.
     */
    public function buildExecution(EngineRunPlan $plan): array;

    /**
     * Execute the engine within an isolated container/sandbox.
     */
    public function execute(EngineRunPlan $plan): EngineExecutionResult;

    /**
     * Collect raw report artifacts from the container output volume.
     */
    public function collect(EngineRunPlan $plan): ?string;

    /**
     * Parse raw report output (JSON/SARIF/Text) into raw finding items.
     */
    public function parse(string $rawOutput): array;

    /**
     * Normalize parsed findings into unified TAMENG SecSys schema.
     */
    public function normalize(array $rawFindings, EngineRunPlan $plan): array;

    /**
     * Clean up temporary containers, volumes, or intermediate artifacts.
     */
    public function cleanup(EngineRunPlan $plan): void;
}
