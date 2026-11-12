<?php

namespace App\Services;

use App\Enums\MessageStatus;
use App\Models\Message;
use App\Models\MessageEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MessageLifecycle
{
    public function transition(Message $message, MessageStatus $to, string $event, string $actorType, ?string $actorId = null, array $metadata = []): Message
    {
        return DB::transaction(function () use ($message, $to, $event, $actorType, $actorId, $metadata): Message {
            $locked = Message::query()->lockForUpdate()->findOrFail($message->id);
            $from = $locked->status;

            if ($from === $to) {
                return $locked;
            }
            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages(['status' => ['Invalid message state transition.']]);
            }

            $locked->status = $to;
            if ($to === MessageStatus::Assigned) {
                $locked->assigned_at = now();
            }
            if ($to === MessageStatus::Sending) {
                $locked->attempt_count++;
            }
            if (in_array($to, [MessageStatus::SendFailed, MessageStatus::DeliveryReported, MessageStatus::DeliveryFailed, MessageStatus::Cancelled], true)) {
                $locked->completed_at = now();
            }
            $locked->save();

            MessageEvent::query()->create([
                'id' => (string) Str::uuid(),
                'message_id' => $locked->id,
                'event_type' => $event,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'metadata' => $metadata,
                'created_at' => now(),
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }
}
