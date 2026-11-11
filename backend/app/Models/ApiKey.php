<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApiKey extends Model
{
    protected $fillable = [
        'project_id',
        'name',
        'prefix',
        'key_hash',
        'daily_message_limit',
        'requests_per_minute',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = ['key_hash'];

    protected $attributes = [
        'daily_message_limit' => 20,
        'requests_per_minute' => 10,
    ];

    protected function casts(): array
    {
        return [
            'daily_message_limit' => 'integer',
            'requests_per_minute' => 'integer',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function dailyUsage(): HasMany
    {
        return $this->hasMany(ApiKeyDailyUsage::class);
    }
}
