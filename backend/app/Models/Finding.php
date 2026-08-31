<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Finding extends Model
{
    protected $fillable = [
        'code',
        'project_id',
        'scan_job_id',
        'scan_run_id',
        'engine_key',
        'rule_id',
        'title',
        'severity_raw',
        'severity',
        'confidence',
        'asset_type',
        'asset_identifier',
        'file_path',
        'line_start',
        'line_end',
        'http_method',
        'endpoint',
        'cwe',
        'owasp',
        'cve',
        'cvss',
        'status',
        'dedup_key',
        'evidence_summary',
        'normalization_metadata',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:2',
            'cvss' => 'decimal:1',
            'evidence_summary' => 'array',
            'normalization_metadata' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scanJob(): BelongsTo
    {
        return $this->belongsTo(ScanJob::class);
    }

    public function scanRun(): BelongsTo
    {
        return $this->belongsTo(ScanRun::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(FindingEvidence::class);
    }
}
