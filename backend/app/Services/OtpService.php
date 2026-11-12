<?php

namespace App\Services;

use App\Enums\MessageStatus;
use App\Models\Device;
use App\Models\Message;
use App\Models\OtpChallenge;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OtpService
{
    public function __construct(
        private MessageIntake $intake,
        private OtpMessageRecall $recall,
        private MessageLifecycle $lifecycle,
    ) {}

    /**
     * Create an OTP challenge only in the same transaction as an accepted message.
     * The challenge stores a hash. The plaintext code is not returned by the OTP
     * endpoints, not shown in admin, and not written to logs. The existing encrypted
     * message body still carries it so the gateway can send the SMS.
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
                    $pendingChallenges = OtpChallenge::query()
                        ->where('project_id', $lockedProject->id)
                        ->where('phone_hash', $phoneHash)
                        ->where('purpose', $purpose)
                        ->where('status', 'pending')
                        ->lockForUpdate()
                        ->get();
                    foreach ($pendingChallenges as $pendingChallenge) {
                        $this->recall->recallSupersededPending($pendingChallenge);
                    }

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

        abort_unless($created instanceof OtpChallenge && $created->message_id === $message->id, 500, 'OTP challenge was not recorded.');

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
                $this->recall->recall($challenge, 'expired');

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

    /**
     * @return array{http_status: int, data: array{status: string, message_recalled: bool, message_id: string}}
     */
    public function cancel(Project $project, string $challengeId): array
    {
        /** @var array{http_status: int, data: array{status: string, message_recalled: bool, message_id: string}} $result */
        $result = DB::transaction(function () use ($project, $challengeId): array {
            $challenge = OtpChallenge::query()
                ->where('project_id', $project->id)
                ->whereKey($challengeId)
                ->lockForUpdate()
                ->first();

            if (! $challenge instanceof OtpChallenge) {
                abort(404);
            }

            $payload = [
                'status' => 'cancelled',
                'message_recalled' => Message::query()->find($challenge->message_id)?->status === MessageStatus::Cancelled,
                'message_id' => $challenge->message_id,
            ];

            if ($challenge->status === 'cancelled') {
                return ['http_status' => 200, 'data' => $payload];
            }

            if (in_array($challenge->status, ['verified', 'exhausted'], true)) {
                return [
                    'http_status' => 409,
                    'data' => [
                        'status' => $challenge->status,
                        'message_recalled' => false,
                        'message_id' => $challenge->message_id,
                        'detail' => 'This OTP challenge is already closed and cannot be cancelled.',
                    ],
                ];
            }

            $message = Message::query()->lockForUpdate()->find($challenge->message_id);

            if ($message instanceof Message && $this->recall->messageIsRecallable($message)) {
                $this->recall->recall($challenge, 'cancelled');

                return [
                    'http_status' => 200,
                    'data' => [
                        'status' => 'cancelled',
                        'message_recalled' => true,
                        'message_id' => $challenge->message_id,
                    ],
                ];
            }

            if ($challenge->status === 'pending') {
                $challenge->forceFill(['status' => 'cancelled'])->save();
            }

            return [
                'http_status' => 409,
                'data' => [
                    'status' => 'cancelled',
                    'message_recalled' => false,
                    'message_id' => $challenge->message_id,
                    'detail' => 'The OTP challenge was cancelled, but send authorization has started. The SMS may already have been submitted and cannot be guaranteed recalled.',
                ],
            ];
        }, attempts: 3);

        return $result;
    }

    /**
     * @return array{send: bool, reason: string}
     */
    public function authorizeDeviceSend(Device $device, string $messageId): array
    {
        return DB::transaction(function () use ($device, $messageId): array {
            // Keep the same challenge-then-message lock order as cancellation.
            $challenge = OtpChallenge::query()
                ->where('message_id', $messageId)
                ->lockForUpdate()
                ->first();
            $message = Message::query()
                ->where('project_id', $device->project_id)
                ->where('device_id', $device->id)
                ->lockForUpdate()
                ->findOrFail($messageId);

            if (! $challenge instanceof OtpChallenge) {
                if (! in_array($message->status, [MessageStatus::Assigned, MessageStatus::AcceptedByDevice], true)) {
                    return ['send' => false, 'reason' => $message->status === MessageStatus::Sending ? 'already_started' : 'not_sendable'];
                }

                return ['send' => true, 'reason' => 'not_otp'];
            }

            if ($message->status === MessageStatus::Cancelled) {
                return ['send' => false, 'reason' => 'cancelled'];
            }

            if ($message->status === MessageStatus::Sending) {
                return ['send' => false, 'reason' => 'already_started'];
            }

            if (! $this->recall->messageIsRecallable($message)) {
                return ['send' => false, 'reason' => 'already_handoff'];
            }

            if (! $this->recall->challengeIsSendable($challenge)) {
                if ($challenge->status === 'pending') {
                    $terminalStatus = $challenge->expires_at?->isPast() ? 'expired' : 'cancelled';
                    $this->recall->recall($challenge, $terminalStatus);
                }

                return match ($challenge->fresh()?->status) {
                    'expired' => ['send' => false, 'reason' => 'expired'],
                    'superseded' => ['send' => false, 'reason' => 'superseded'],
                    'cancelled' => ['send' => false, 'reason' => 'cancelled'],
                    default => ['send' => false, 'reason' => 'not_authorized'],
                };
            }

            if ($message->status !== MessageStatus::AcceptedByDevice) {
                return ['send' => false, 'reason' => 'device_not_accepted'];
            }

            // This is the cancellation boundary: once reserved as SENDING,
            // cancellation may invalidate verification but must not claim recall.
            $this->lifecycle->transition(
                $message,
                MessageStatus::Sending,
                'otp.send_authorized',
                'device',
                $device->id,
            );

            return ['send' => true, 'reason' => 'authorized'];
        }, attempts: 3);
    }

    public function challengeIsSendable(OtpChallenge $challenge): bool
    {
        return $this->recall->challengeIsSendable($challenge);
    }

    /**
     * Skip stale OTP rows during claim selection.
     */
    public function refreshUnsendableChallenge(Message $message, OtpChallenge $challenge): void
    {
        if ($message->status === MessageStatus::Cancelled) {
            return;
        }

        if (! $this->recall->messageIsRecallable($message)) {
            return;
        }

        if ($challenge->status === 'pending' && $challenge->expires_at?->isPast()) {
            $this->recall->recall($challenge, 'expired');

            return;
        }

        if ($this->recall->challengeIsSendable($challenge)) {
            return;
        }

        $status = in_array($challenge->status, ['cancelled', 'expired', 'superseded'], true)
            ? $challenge->status
            : 'cancelled';

        $this->recall->recall($challenge, $status);
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
