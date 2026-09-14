<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    protected $fillable = [
        'user_id',
        'alert_email',
        'alert_telegram',
        'telegram_chat_id',
        'alert_min_severity',
        'theme',
    ];

    protected function casts(): array
    {
        return [
            'alert_email' => 'boolean',
            'alert_telegram' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
