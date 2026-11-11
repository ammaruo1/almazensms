<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    use HasUuids;

    protected $fillable = ['aggregate_type', 'aggregate_id', 'event_type', 'payload', 'available_at', 'processed_at', 'attempts', 'last_error'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'available_at' => 'datetime', 'lease_expires_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
