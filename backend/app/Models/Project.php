<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = ['owner_id', 'name', 'status', 'daily_message_limit'];

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
}
