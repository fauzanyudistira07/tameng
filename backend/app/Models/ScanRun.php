<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanRun extends Model
{
    protected $fillable = [
        'scan_job_id',
        'worker_id',
        'engine_key',
        'status',
        'exit_code',
        'started_at',
        'finished_at',
        'failure_reason',
        'command_spec',
        'runtime_metrics',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'command_spec' => 'array',
            'runtime_metrics' => 'array',
        ];
    }

    public function scanJob(): BelongsTo
    {
        return $this->belongsTo(ScanJob::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
