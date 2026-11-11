<?php

namespace Tests\Feature;

use App\Models\OutboxEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class QueueDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_database_queue_diagnostics_do_not_treat_redis_as_required_or_cron_as_confirmed(): void
    {
        config(['queue.default' => 'database']);
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $createdAt = 1700000000;
        DB::table('jobs')->insert([
            'queue' => 'outbox',
            'payload' => '{"displayName":"App\\\\Jobs\\\\ProcessOutboxEvent"}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $createdAt,
            'created_at' => $createdAt,
        ]);
        $event = OutboxEvent::query()->create([
            'id' => (string) Str::uuid(),
            'aggregate_type' => 'message',
            'aggregate_id' => 'diagnostic-event',
            'event_type' => 'message.accepted',
            'payload' => ['message_id' => 'diagnostic-event'],
            'available_at' => now(),
        ]);
        DB::table('outbox_events')->where('id', $event->id)->update(['created_at' => '2026-01-01 00:00:00']);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'outbox',
            'payload' => '{}',
            'exception' => 'worker stopped',
            'failed_at' => '2026-01-02 00:00:00',
        ]);

        $page = $this->actingAs($admin, 'web')->get(route('admin.diagnostics'));
        $page->assertOk()
            ->assertSee('Database Queue')
            ->assertSee('غير مؤكد. تعريف الجدولة')
            ->assertSee(Carbon::createFromTimestampUTC($createdAt)->toIso8601String())
            ->assertSee('2026-01-01')
            ->assertDontSee('worker stopped')
            ->assertDontSee('تعذر التحقق من اتصال Redis')
            ->assertDontSee('Redis');

        $this->artisan('scheduler:heartbeat')->assertSuccessful();
        $this->assertDatabaseHas('scheduler_heartbeats', ['name' => 'schedule']);
        $this->get(route('admin.diagnostics'))
            ->assertOk()
            ->assertSee('آخر تشغيل مسجّل')
            ->assertSee('scheduler:heartbeat');
    }

    public function test_non_database_queue_does_not_claim_redis_is_missing(): void
    {
        config(['queue.default' => 'sync']);
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();

        $this->actingAs($admin, 'web')->get(route('admin.diagnostics'))
            ->assertOk()
            ->assertSee('sync')
            ->assertSee('غير مؤكد')
            ->assertDontSee('تعذر التحقق من اتصال Redis')
            ->assertDontSee('Redis');
    }
}
