<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanProfileEngine extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_code',
        'security_engine_id',
        'is_required',
        'execution_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'execution_order' => 'integer',
    ];

    public function securityEngine(): BelongsTo
    {
        return $this->belongsTo(SecurityEngine::class);
    }
}
