<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKeyDailyUsage extends Model
{
    protected $table = 'api_key_daily_usage';

    protected $fillable = ['api_key_id', 'usage_date', 'message_count'];

    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'message_count' => 'integer',
        ];
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }
}
