<?php

namespace App\Services;

use App\Enums\MessageStatus;
use App\Models\Message;
use App\Models\OtpChallenge;
use App\Models\OutboxEvent;

class OtpMessageRecall
{
    public const RECALLED_BODY = 'تم سحب رسالة OTP ولا تحتوي رمزًا.';

    private const OUTBOX_SUPPRESS_ERROR = 'suppressed:otp_not_sendable';

    public function __construct(private MessageLifecycle $lifecycle) {}

    public function messageIsRecallable(Message $message): bool
    {
        return in_array($message->status, [
            MessageStatus::Queued,
            MessageStatus::Assigned,
            MessageStatus::AcceptedByDevice,
        ], true);
    }

    public function challengeIsSendable(OtpChallenge $challenge): bool
    {
        return $challenge->status === 'pending'
            && $challenge->expires_at !== null
            && $challenge->expires_at->isFuture();
    }

    /**
     * Mark the challenge terminal and cancel the linked message when it has not
     * yet been handed to the cellular network.
     *
     * @return bool Whether the message row was transitioned to cancelled.
     */
    public function recall(OtpChallenge $challenge, string $challengeStatus): bool
    {
        abort_unless(in_array($challengeStatus, ['cancelled', 'expired', 'superseded'], true), 500);

        $challenge->forceFill(['status' => $challengeStatus])->save();

        $message = Message::query()->lockForUpdate()->find($challenge->message_id);
        if (! $message instanceof Message) {
            return false;
        }

        if (! $this->messageIsRecallable($message)) {
            return false;
        }

        $message->forceFill(['body' => self::RECALLED_BODY])->save();
        $this->lifecycle->transition(
            $message,
            MessageStatus::Cancelled,
            'otp.recalled',
            'system',
            null,
            ['challenge_status' => $challengeStatus],
        );

        $this->suppressOutbox($message->id);

        return true;
    }

    public function recallSupersededPending(OtpChallenge $challenge): bool
    {
        if ($challenge->status !== 'pending') {
            return false;
        }

        return $this->recall($challenge, 'superseded');
    }

    private function suppressOutbox(string $messageId): void
    {
        OutboxEvent::query()
            ->where('aggregate_type', 'message')
            ->where('aggregate_id', $messageId)
            ->whereNull('processed_at')
            ->update([
                'processed_at' => now(),
                'last_error' => self::OUTBOX_SUPPRESS_ERROR,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
    }
}
