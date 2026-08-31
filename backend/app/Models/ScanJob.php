<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScanJob extends Model
{
    protected $fillable = [
        'code',
        'project_id',
        'repository_id',
        'target_id',
        'scan_profile_id',
        'authorization_id',
        'created_by',
        'status',
        'progress',
        'attempt',
        'queued_at',
        'started_at',
        'finished_at',
        'cancelled_at',
        'failure_reason',
        'engine_plan',
        'execution_policy_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'engine_plan' => 'array',
            'execution_policy_snapshot' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    public function scanProfile(): BelongsTo
    {
        return $this->belongsTo(ScanProfile::class);
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(Authorization::class);
    }

    public function scanRuns(): HasMany
    {
        return $this->hasMany(ScanRun::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
