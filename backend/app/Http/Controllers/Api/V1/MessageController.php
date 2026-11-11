<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageEvent;
use App\Models\Project;
use App\Services\MessageIntake;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function store(Request $request, MessageIntake $intake): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('apiProject');
        $data = $request->validate([
            'to' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'body' => ['required', 'string', 'max:5000'],
        ]);
        $idempotencyKey = MessageIntake::idempotencyKey($request);
        $payloadHash = hash_hmac('sha256', json_encode([$data['to'], $data['body']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (string) config('app.key'));
        $existing = $project->messages()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing instanceof Message) {
            return $this->idempotentResponse($existing, $payloadHash);
        }

        try {
            [$message, $duplicate] = $intake->accept(
                $project,
                (int) $request->attributes->get('apiKeyId'),
                $idempotencyKey,
                $data['to'],
                $data['body'],
                $payloadHash,
            );
        } catch (QueryException $exception) {
            $existing = $project->messages()->where('idempotency_key', $idempotencyKey)->first();
            if (! $existing instanceof Message) {
                throw $exception;
            }

            return $this->idempotentResponse($existing, $payloadHash);
        }

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
