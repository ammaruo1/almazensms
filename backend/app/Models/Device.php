<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'name', 'token_prefix', 'token_hash', 'status', 'metadata', 'fcm_token', 'last_heartbeat_at', 'revoked_at'];

    protected $hidden = ['token_hash', 'fcm_token'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'last_heartbeat_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
