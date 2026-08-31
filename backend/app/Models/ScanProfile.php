<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScanProfile extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'allowed_target_types',
        'engine_keys',
        'policy',
        'active_testing',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'allowed_target_types' => 'array',
            'engine_keys' => 'array',
            'policy' => 'array',
            'active_testing' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
