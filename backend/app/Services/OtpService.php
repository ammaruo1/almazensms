<?php

namespace App\Services;

use App\Models\Message;
use App\Models\OtpChallenge;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OtpService
{
    public function __construct(private MessageIntake $intake) {}

    /**
     * Create an OTP challenge only after the existing message path accepts the SMS.
     * The plaintext code is never returned, logged, or stored. The message body is
     * the same encrypted payload the gateway already claims.
     *
     * @return array{challenge: OtpChallenge, duplicate: bool}
     */
    public function request(Project $project, int $apiKeyId, string $idempotencyKey, string $destination, string $purpose): array
    {
        $payloadHash = OtpChallenge::hashRequest($destination, $purpose);
        $existing = $this->existingChallenge($project, $idempotencyKey, $payloadHash);
        if ($existing instanceof OtpChallenge) {
            return ['challenge' => $existing, 'duplicate' => true];
        }

        abort_unless($project->otp_enabled, 403, 'OTP is disabled for this project.');

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $phoneHash = OtpChallenge::hashPhone($destination);
        $phoneMask = OtpChallenge::maskPhone($destination);
        $created = null;

        try {
            [$message, $duplicate] = $this->intake->accept(
                $project,
                $apiKeyId,
                $idempotencyKey,
                $destination,
                'رمز التحقق: '.$code,
                $payloadHash,
                function (Project $locked) use ($phoneHash, $purpose): void {
                    abort_unless($locked->otp_enabled, 403, 'OTP is disabled for this project.');

                    $recent = OtpChallenge::query()
                        ->where('project_id', $locked->id)
                        ->where('phone_hash', $phoneHash)
                        ->where('purpose', $purpose)
                        ->where('created_at', '>=', now()->subSeconds((int) $locked->otp_resend_cooldown_seconds))
                        ->lockForUpdate()
                        ->exists();
                    abort_if($recent, 429, 'OTP resend cooldown is active.');
                },
                function (Message $message) use (&$created, $purpose, $phoneHash, $phoneMask, $code, $idempotencyKey): void {
                    $lockedProject = Project::query()->lockForUpdate()->findOrFail($message->project_id);
                    OtpChallenge::query()
                        ->where('project_id', $lockedProject->id)
                        ->where('phone_hash', $phoneHash)
                        ->where('purpose', $purpose)
                        ->where('status', 'pending')
                        ->update(['status' => 'superseded', 'updated_at' => now()]);

                    $created = OtpChallenge::query()->create([
                        'id' => (string) Str::uuid(),
                        'project_id' => $lockedProject->id,
                        'api_key_id' => $message->api_key_id,
                        'message_id' => $message->id,
                        'purpose' => $purpose,
                        'phone_hash' => $phoneHash,
                        'phone_mask' => $phoneMask,
                        'code_hash' => OtpChallenge::hashCode($code),
                        'status' => 'pending',
                        'attempts' => 0,
                        'max_attempts' => (int) $lockedProject->otp_max_attempts,
                        'expires_at' => now()->addSeconds((int) $lockedProject->otp_ttl_seconds),
                        'idempotency_key' => $idempotencyKey,
                    ]);
                },
            );
        } catch (QueryException $exception) {
            $replay = $this->existingChallenge($project, $idempotencyKey, $payloadHash);
            if (! $replay instanceof OtpChallenge) {
                throw $exception;
            }

            return ['challenge' => $replay, 'duplicate' => true];
        }

        if ($duplicate) {
            $replay = $this->existingChallenge($project, $idempotencyKey, $payloadHash);
            abort_unless($replay instanceof OtpChallenge, 409, 'Idempotency-Key was already used for a different payload.');

            return ['challenge' => $replay, 'duplicate' => true];
        }

        abort_unless($created instanceof OtpChallenge, 500, 'OTP challenge was not recorded.');

        return ['challenge' => $created, 'duplicate' => false];
    }

    /**
     * @return array{verified: bool, status: string}
     */
    public function verify(Project $project, string $destination, string $code, string $purpose): array
    {
        $submittedHash = OtpChallenge::hashCode($code);

        /** @var array{verified: bool, status: string} $result */
        $result = DB::transaction(function () use ($project, $destination, $purpose, $submittedHash): array {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            abort_unless($lockedProject->otp_enabled, 403, 'OTP is disabled for this project.');

            $challenge = OtpChallenge::query()
                ->where('project_id', $lockedProject->id)
                ->where('phone_hash', OtpChallenge::hashPhone($destination))
                ->where('purpose', $purpose)
                ->where('status', 'pending')
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if (! $challenge instanceof OtpChallenge) {
                return ['verified' => false, 'status' => 'invalid'];
            }

            if ($challenge->expires_at?->isPast()) {
                $challenge->forceFill(['status' => 'expired'])->save();

                return ['verified' => false, 'status' => 'expired'];
            }

            if ((int) $challenge->attempts >= (int) $challenge->max_attempts) {
                $challenge->forceFill(['status' => 'exhausted'])->save();

                return ['verified' => false, 'status' => 'exhausted'];
            }

            $challenge->increment('attempts');
            $challenge->refresh();

            if (! hash_equals((string) $challenge->code_hash, $submittedHash)) {
                if ((int) $challenge->attempts >= (int) $challenge->max_attempts) {
                    $challenge->forceFill(['status' => 'exhausted'])->save();

                    return ['verified' => false, 'status' => 'exhausted'];
                }

                return ['verified' => false, 'status' => 'invalid'];
            }

            $challenge->forceFill(['status' => 'verified', 'verified_at' => now()])->save();

            return ['verified' => true, 'status' => 'verified'];
        }, attempts: 3);

        return $result;
    }

    private function existingChallenge(Project $project, string $idempotencyKey, string $payloadHash): ?OtpChallenge
    {
        $message = $project->messages()->where('idempotency_key', $idempotencyKey)->first();
        if (! $message instanceof Message) {
            return null;
        }

        abort_unless(hash_equals($message->payload_hash, $payloadHash), 409, 'Idempotency-Key was already used for a different payload.');

        $challenge = OtpChallenge::query()->where('message_id', $message->id)->first();
        abort_unless($challenge instanceof OtpChallenge, 409, 'Idempotency-Key was already used for a different payload.');

        return $challenge;
    }
}
