<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class AuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['user_id', 'project_id', 'action', 'subject_type', 'subject_id', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function getTestDestinationAttribute(): ?string
    {
        return $this->decryptMetadataField('destination_encrypted');
    }

    public function getTestMessageBodyAttribute(): ?string
    {
        return $this->decryptMetadataField('body_encrypted');
    }

    private function decryptMetadataField(string $key): ?string
    {
        $encrypted = $this->metadata[$key] ?? null;
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return null;
        }
    }
}
