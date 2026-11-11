<?php

namespace Tests\Feature;

use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SharedHostingQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_queue_worker_processes_outbox_without_redis(): void
    {
        config(['queue.default' => 'database']);
        $event = OutboxEvent::query()->create([
            'id' => (string) Str::uuid(),
            'aggregate_type' => 'message',
            'aggregate_id' => 'shared-hosting-test',
            'event_type' => 'message.accepted',
            'payload' => ['message_id' => 'shared-hosting-test'],
            'available_at' => now(),
        ]);

        $this->artisan('outbox:dispatch')->assertSuccessful();
        $this->assertDatabaseHas('jobs', ['queue' => 'outbox']);
        $this->assertNull($event->fresh()->processed_at);

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--queue' => 'outbox',
            '--stop-when-empty' => true,
            '--max-time' => 5,
            '--tries' => 1,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertNotNull($event->fresh()->processed_at);
        $this->assertDatabaseCount('jobs', 0);
    }
}
