<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Artifact extends Model
{
    protected $fillable = [
        'scan_job_id',
        'scan_run_id',
        'finding_id',
        'type',
        'storage_disk',
        'path',
        'sha256',
        'size_bytes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function scanJob(): BelongsTo
    {
        return $this->belongsTo(ScanJob::class);
    }

    public function scanRun(): BelongsTo
    {
        return $this->belongsTo(ScanRun::class);
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }
}
