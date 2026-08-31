<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FindingEvidence extends Model
{
    use HasFactory;

    protected $table = 'finding_evidences';

    protected $fillable = [
        'finding_id',
        'engine_key',
        'engine_version',
        'confidence',
        'fingerprint_hash',
        'evidence_summary',
        'raw_artifact_path',
    ];

    protected $casts = [
        'confidence' => 'decimal:2',
        'evidence_summary' => 'array',
    ];

    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }
}
