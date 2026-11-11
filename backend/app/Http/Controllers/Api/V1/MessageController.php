<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MessageStatus;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageEvent;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Models\ProjectDailyUsage;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('apiProject');
        $data = $request->validate([
            'to' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'body' => ['required', 'string', 'max:5000'],
        ]);
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || ! preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $idempotencyKey)) {
            return response()->json(['message' => 'A valid Idempotency-Key header is required.'], 422);
        }

        $payloadHash = hash_hmac('sha256', json_encode([$data['to'], $data['body']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), config('app.key'));
        $existing = $project->messages()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $this->idempotentResponse($existing, $payloadHash);
        }

        try {
            /** @var array{0: Message, 1: bool} $result */
            $result = DB::transaction(function () use ($project, $request, $data, $idempotencyKey, $payloadHash): array {
                $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
                $existing = $lockedProject->messages()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return [$existing, true];
                }

                $usageDate = now('UTC')->toDateString();
                $usage = $lockedProject->dailyUsage()->whereDate('usage_date', $usageDate)->first();
                if (! $usage) {
                    $usage = $lockedProject->dailyUsage()->create(['usage_date' => $usageDate, 'message_count' => 0]);
                }
                $usage = ProjectDailyUsage::query()->lockForUpdate()->findOrFail($usage->id);
                abort_if($usage->message_count >= $lockedProject->daily_message_limit, 429, 'Project daily message limit reached.');
                $usage->increment('message_count');

                $message = $lockedProject->messages()->create([
                    'id' => (string) Str::uuid(),
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'destination' => $data['to'],
                    'body' => $data['body'],
                    'status' => MessageStatus::Queued,
                    'queued_at' => now(),
                ]);

                MessageEvent::query()->create([
                    'id' => (string) Str::uuid(),
                    'message_id' => $message->id,
                    'event_type' => 'message.accepted',
                    'from_status' => null,
                    'to_status' => MessageStatus::Queued->value,
                    'actor_type' => 'project_api',
                    'actor_id' => (string) $request->attributes->get('apiKeyId'),
                    'metadata' => [],
                    'created_at' => now(),
                ]);

                OutboxEvent::query()->create([
                    'id' => (string) Str::uuid(),
                    'aggregate_type' => 'message',
                    'aggregate_id' => $message->id,
                    'event_type' => 'message.accepted',
                    'payload' => ['message_id' => $message->id, 'project_id' => $lockedProject->id],
                    'available_at' => now(),
                ]);

                return [$message, false];
            }, attempts: 3);
        } catch (QueryException $exception) {
            $existing = $project->messages()->where('idempotency_key', $idempotencyKey)->first();
            if (! $existing) {
                throw $exception;
            }

            return $this->idempotentResponse($existing, $payloadHash);
        }

        [$message, $duplicate] = $result;
        if ($duplicate) {
            return $this->idempotentResponse($message, $payloadHash);
        }

        return response()->json(['data' => $this->resource($message), 'duplicate' => false], 202);
    }

    public function show(Request $request, string $messageId): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('apiProject');
        $message = $project->messages()->with('events')->findOrFail($messageId);

        return response()->json(['data' => $this->resource($message) + [
            'events' => $message->events->map(fn (MessageEvent $event): array => [
                'type' => $event->event_type,
                'from' => $event->from_status,
                'to' => $event->to_status,
                'at' => $event->created_at?->toISOString(),
                'metadata' => $event->metadata,
            ]),
        ]]);
    }

    private function idempotentResponse(Message $message, string $payloadHash): JsonResponse
    {
        if (! hash_equals($message->payload_hash, $payloadHash)) {
            return response()->json(['message' => 'Idempotency-Key was already used for a different payload.'], 409);
        }

        return response()->json(['data' => $this->resource($message), 'duplicate' => true], 200);
    }

    private function resource(Message $message): array
    {
        return [
            'id' => $message->id,
            'status' => $message->status->value,
            'created_at' => $message->queued_at?->toISOString(),
            'attempt_count' => $message->attempt_count,
            'device_id' => $message->device_id,
        ];
    }
}
