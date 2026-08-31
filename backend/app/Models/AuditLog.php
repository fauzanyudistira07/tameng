<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'project_id',
        'authorization_id',
        'scan_job_id',
        'action',
        'result',
        'actor_ip',
        'target_type',
        'target_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(Authorization::class);
    }

    public function scanJob(): BelongsTo
    {
        return $this->belongsTo(ScanJob::class);
    }
}
