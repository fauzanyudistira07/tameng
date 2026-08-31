<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Repository extends Model
{
    protected $fillable = [
        'project_id',
        'provider',
        'name',
        'url',
        'default_branch',
        'verification_status',
        'verified_at',
        'verified_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
