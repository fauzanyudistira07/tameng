<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngineVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'security_engine_id',
        'version',
        'container_image',
        'adapter_version',
        'is_active',
        'changelog',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function securityEngine(): BelongsTo
    {
        return $this->belongsTo(SecurityEngine::class);
    }
}
