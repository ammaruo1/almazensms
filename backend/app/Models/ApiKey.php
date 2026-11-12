<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
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
        'secret_encrypted',
        'daily_message_limit',
        'requests_per_minute',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = ['key_hash', 'secret_encrypted'];

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

    /**
     * @return array{prefix: string, key_hash: string, secret_encrypted: string}
     */
    public static function protect(string $plain): array
    {
        return [
            'prefix' => substr($plain, 0, 24),
            'key_hash' => hash('sha256', $plain),
            'secret_encrypted' => encrypt($plain),
        ];
    }

    public function plainSecret(): ?string
    {
        if (! is_string($this->secret_encrypted) || $this->secret_encrypted === '') {
            return null;
        }

        try {
            $plain = decrypt($this->secret_encrypted);
        } catch (DecryptException) {
            return null;
        }

        if (! is_string($plain) || ! hash_equals($this->key_hash, hash('sha256', $plain))) {
            return null;
        }

        return $plain;
    }
}
