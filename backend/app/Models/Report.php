<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    protected $fillable = [
        'scan_job_id',
        'type',
        'status',
        'format',
        'artifact_path',
        'generated_by',
        'generated_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function scanJob(): BelongsTo
    {
        return $this->belongsTo(ScanJob::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
