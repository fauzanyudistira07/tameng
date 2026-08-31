<?php

namespace App\Services\Engines\Support;

class EngineExecutionResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?int $exitCode,
        public readonly array $commandSpec,
        public readonly array $runtimeMetrics = [],
        public readonly ?string $rawOutput = null,
        public readonly ?string $failureReason = null,
        public readonly array $normalizedFindings = [],
    ) {}

    public static function skipped(array $commandSpec, string $reason): self
    {
        return new self(
            status: 'skipped',
            exitCode: null,
            commandSpec: $commandSpec,
            runtimeMetrics: ['skipped_reason' => $reason],
            failureReason: $reason,
        );
    }

    public static function denied(array $commandSpec, string $reason): self
    {
        return new self(
            status: 'denied',
            exitCode: null,
            commandSpec: $commandSpec,
            runtimeMetrics: ['denied_reason' => $reason],
            failureReason: $reason,
        );
    }
}
