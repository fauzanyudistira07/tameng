<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PolicyDecision extends Model
{
    protected $fillable = [
        'authorization_id',
        'scan_job_id',
        'gateway',
        'decision',
        'reason_code',
        'request_snapshot',
        'policy_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'request_snapshot' => 'array',
            'policy_snapshot' => 'array',
        ];
    }
}
