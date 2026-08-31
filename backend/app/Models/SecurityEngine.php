<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SecurityEngine extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'domain',
        'category',
        'version',
        'adapter_version',
        'container_image',
        'resource_class',
        'enabled',
        'status',
        'supported_targets',
        'default_timeout',
        'cpu_limit',
        'memory_limit_mb',
        'description',
        'last_health_check',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'supported_targets' => 'array',
        'default_timeout' => 'integer',
        'cpu_limit' => 'decimal:2',
        'memory_limit_mb' => 'integer',
        'last_health_check' => 'datetime',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(EngineVersion::class);
    }

    public function activeVersion()
    {
        return $this->hasOne(EngineVersion::class)->where('is_active', true);
    }

    public function profileEngines(): HasMany
    {
        return $this->hasMany(ScanProfileEngine::class);
    }

    public function scopeActive($query)
    {
        return $query->where('enabled', true)->where('status', '!=', 'DISABLED');
    }

    public function scopeByDomain($query, string $domain)
    {
        return $query->where('domain', strtoupper($domain));
    }
}
