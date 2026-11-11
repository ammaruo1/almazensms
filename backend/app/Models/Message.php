<?php

namespace App\Models;

use App\Enums\MessageStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'device_id', 'idempotency_key', 'payload_hash', 'destination', 'body', 'status', 'attempt_count', 'queued_at', 'assigned_at', 'completed_at'];

    protected $hidden = ['destination', 'body', 'payload_hash', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'destination' => 'encrypted',
            'body' => 'encrypted',
            'status' => MessageStatus::class,
            'queued_at' => 'datetime',
            'assigned_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MessageEvent::class)->orderBy('created_at');
    }
}
