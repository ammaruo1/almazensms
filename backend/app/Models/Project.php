<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        'owner_id',
        'name',
        'status',
        'daily_message_limit',
        'otp_enabled',
        'otp_ttl_seconds',
        'otp_max_attempts',
        'otp_resend_cooldown_seconds',
    ];

    protected $attributes = [
        'daily_message_limit' => 20,
        'otp_enabled' => false,
        'otp_ttl_seconds' => 300,
        'otp_max_attempts' => 5,
        'otp_resend_cooldown_seconds' => 60,
    ];

    protected function casts(): array
    {
        return [
            'daily_message_limit' => 'integer',
            'otp_enabled' => 'boolean',
            'otp_ttl_seconds' => 'integer',
            'otp_max_attempts' => 'integer',
            'otp_resend_cooldown_seconds' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function dailyUsage(): HasMany
    {
        return $this->hasMany(ProjectDailyUsage::class);
    }

    public function otpChallenges(): HasMany
    {
        return $this->hasMany(OtpChallenge::class);
    }
}
