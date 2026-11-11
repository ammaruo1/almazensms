<?php

namespace App\Console\Commands;

use App\Jobs\ProcessOutboxEvent;
use App\Models\OutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class DispatchOutbox extends Command
{
    protected $signature = 'outbox:dispatch {--limit=100}';

    protected $description = 'Publish pending transactional outbox events to the configured queue.';

    public function handle(): int
    {
        $limit = max(1, min((int) $this->option('limit'), 500));
        $ids = DB::transaction(function () use ($limit): array {
            $events = OutboxEvent::query()
                ->whereNull('processed_at')
                ->where('available_at', '<=', now())
                ->where(fn ($query) => $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now()))
                ->orderBy('available_at')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($events as $event) {
                $event->forceFill(['lease_expires_at' => now()->addMinutes(5), 'attempts' => $event->attempts + 1])->save();
            }

            return $events->modelKeys();
        }, attempts: 3);

        $failed = 0;
        foreach ($ids as $id) {
            try {
                ProcessOutboxEvent::dispatch((string) $id)->onQueue('outbox');
            } catch (Throwable) {
                $failed++;
                OutboxEvent::query()->whereKey($id)->whereNull('processed_at')->update([
                    'lease_expires_at' => null,
                    'last_error' => 'Queue broker unavailable; event remains pending.',
                    'updated_at' => now(),
                ]);
            }
        }

        $this->info('Outbox events published: '.(count($ids) - $failed).'; pending after broker errors: '.$failed.'.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
