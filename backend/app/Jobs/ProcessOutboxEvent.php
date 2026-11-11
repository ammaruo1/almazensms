<?php

namespace App\Jobs;

use App\Models\OutboxEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessOutboxEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [5, 15, 30, 60];

    public function __construct(public readonly string $eventId) {}

    public function handle(): void
    {
        DB::transaction(function (): void {
            $event = OutboxEvent::query()->lockForUpdate()->find($this->eventId);
            if (! $event || $event->processed_at) {
                return;
            }

            // This job confirms durable publication to Redis. The device claim endpoint
            // reads the committed MySQL message row; sending remains Phase 4 work.
            $event->forceFill(['processed_at' => now(), 'lease_expires_at' => null, 'last_error' => null])->save();
        }, attempts: 3);
    }

    public function failed(?\Throwable $exception): void
    {
        OutboxEvent::query()->whereKey($this->eventId)->whereNull('processed_at')->update([
            'lease_expires_at' => null,
            'last_error' => 'Queue job exhausted retries.',
            'updated_at' => now(),
        ]);
    }
}
