<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtpChallenge extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id',
        'api_key_id',
        'message_id',
        'purpose',
        'phone_hash',
        'phone_mask',
        'code_hash',
        'status',
        'attempts',
        'max_attempts',
        'expires_at',
        'verified_at',
        'idempotency_key',
    ];

    protected $hidden = ['code_hash', 'phone_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', 'otp-code:'.$code, (string) config('app.key'));
    }

    public static function hashPhone(string $e164): string
    {
        return hash_hmac('sha256', 'otp-phone:'.$e164, (string) config('app.key'));
    }

    public static function hashRequest(string $e164, string $purpose): string
    {
        return hash_hmac(
            'sha256',
            json_encode([$e164, $purpose, 'otp'], JSON_THROW_ON_ERROR),
            (string) config('app.key'),
        );
    }

    public static function maskPhone(string $e164): string
    {
        $digits = preg_replace('/\D+/', '', $e164) ?? '';
        if (strlen($digits) <= 2) {
            return '+**';
        }

        return '+'.str_repeat('*', strlen($digits) - 2).substr($digits, -2);
    }
}
