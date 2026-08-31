<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Authorization extends Model
{
    protected $fillable = [
        'code',
        'project_id',
        'repository_id',
        'target_id',
        'scan_profile_id',
        'requested_by',
        'approved_by',
        'status',
        'valid_from',
        'valid_until',
        'max_concurrency',
        'rate_limit_per_minute',
        'allowed_engines',
        'allowed_scope_snapshot',
        'denied_scope_snapshot',
        'policy_snapshot',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'allowed_engines' => 'array',
            'allowed_scope_snapshot' => 'array',
            'denied_scope_snapshot' => 'array',
            'policy_snapshot' => 'array',
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
}
