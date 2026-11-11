<?php

namespace App\Services;

use App\Enums\MessageStatus;
use App\Models\ApiKey;
use App\Models\ApiKeyDailyUsage;
use App\Models\Message;
use App\Models\MessageEvent;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Models\ProjectDailyUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessageIntake
{
    public static function idempotencyKey(Request $request): string
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || ! preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $idempotencyKey)) {
            abort(422, 'A valid Idempotency-Key header is required.');
        }

        return $idempotencyKey;
    }

    /**
     * Persist one accepted message, its lifecycle event, and its outbox row.
     *
     * Quota is consumed only for a new key. A replay returns the existing row
     * before either daily counter or the per-minute count is touched.
     *
     * @param  null|callable(Project, ApiKey): void  $beforeConsume
     * @param  null|callable(Message): void  $afterAccepted
     * @return array{0: Message, 1: bool}
     */
    public function accept(
        Project $project,
        int $apiKeyId,
        string $idempotencyKey,
        string $destination,
        string $body,
        string $payloadHash,
        ?callable $beforeConsume = null,
        ?callable $afterAccepted = null,
    ): array {
        /** @var array{0: Message, 1: bool} $result */
        $result = DB::transaction(function () use ($project, $apiKeyId, $idempotencyKey, $destination, $body, $payloadHash, $beforeConsume, $afterAccepted): array {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            $lockedKey = ApiKey::query()->lockForUpdate()->findOrFail($apiKeyId);
            abort_unless($lockedKey->project_id === $lockedProject->id && $lockedKey->revoked_at === null, 401);

            $existing = $lockedProject->messages()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing instanceof Message) {
                return [$existing, true];
            }

            if ($beforeConsume !== null) {
                $beforeConsume($lockedProject, $lockedKey);
            }

            $this->consumeQuota($lockedProject, $lockedKey);

            $message = $lockedProject->messages()->create([
                'id' => (string) Str::uuid(),
                'api_key_id' => $lockedKey->id,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'destination' => $destination,
                'body' => $body,
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
                'actor_id' => (string) $lockedKey->id,
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

            if ($afterAccepted !== null) {
                $afterAccepted($message);
            }

            return [$message, false];
        }, attempts: 3);

        return $result;
    }

    private function consumeQuota(Project $project, ApiKey $apiKey): void
    {
        $recent = Message::query()
            ->where('api_key_id', $apiKey->id)
            ->where('created_at', '>=', now()->subMinute())
            ->count();
        abort_if($recent >= (int) $apiKey->requests_per_minute, 429, 'API key request rate limit reached.');

        $usageDate = now('UTC')->toDateString();
        $keyUsage = $this->lockedKeyUsage($apiKey, $usageDate);
        $projectUsage = $this->lockedProjectUsage($project, $usageDate);
        $remaining = EffectiveQuota::dailyRemaining(
            (int) $apiKey->daily_message_limit,
            (int) $keyUsage->message_count,
            (int) $project->daily_message_limit,
            (int) $projectUsage->message_count,
        );
        abort_if($remaining < 1, 429, 'Daily message quota reached.');

        $keyUsage->increment('message_count');
        $projectUsage->increment('message_count');
    }

    private function lockedKeyUsage(ApiKey $apiKey, string $usageDate): ApiKeyDailyUsage
    {
        $usage = $apiKey->dailyUsage()->whereDate('usage_date', $usageDate)->first();
        if (! $usage) {
            $usage = $apiKey->dailyUsage()->create(['usage_date' => $usageDate, 'message_count' => 0]);
        }

        return ApiKeyDailyUsage::query()->lockForUpdate()->findOrFail($usage->id);
    }

    private function lockedProjectUsage(Project $project, string $usageDate): ProjectDailyUsage
    {
        $usage = $project->dailyUsage()->whereDate('usage_date', $usageDate)->first();
        if (! $usage) {
            $usage = $project->dailyUsage()->create(['usage_date' => $usageDate, 'message_count' => 0]);
        }

        return ProjectDailyUsage::query()->lockForUpdate()->findOrFail($usage->id);
    }
}
