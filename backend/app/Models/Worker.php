<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Worker extends Model
{
    protected $fillable = [
        'name',
        'hostname',
        'status',
        'capabilities',
        'resource_limits',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'resource_limits' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }
}
