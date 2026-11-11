<?php

namespace Tests\Feature;

use App\Enums\MessageStatus;
use App\Jobs\ProcessOutboxEvent;
use App\Models\ApiKey;
use App\Models\Device;
use App\Models\Message;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class GatewayApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_issues_revocable_sanctum_token_and_disabled_users_are_rejected(): void
    {
        $user = User::factory()->create(['password' => 'safe-test-password']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'safe-test-password',
            'device_name' => 'test-suite',
        ])->assertCreated()->assertJsonPath('user.id', $user->id);
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'wrong-password', 'device_name' => 'test-suite',
        ])->assertUnauthorized();

        $user->forceFill(['status' => 'disabled'])->save();
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'safe-test-password',
            'device_name' => 'test-suite',
        ])->assertForbidden();
    }

    public function test_admin_can_manage_user_access_but_cannot_lock_out_self(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $operator = User::factory()->create();
        $adminToken = $admin->createToken('admin-tests')->plainTextToken;
        $operatorToken = $operator->createToken('operator-tests')->plainTextToken;

        $this->withToken($operatorToken)->getJson('/api/v1/users')->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($adminToken)->getJson('/api/v1/users')->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($adminToken)->patchJson("/api/v1/users/{$operator->id}", ['status' => 'disabled'])
            ->assertOk()->assertJsonPath('data.status', 'disabled');
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.updated', 'subject_id' => (string) $operator->id]);
        Auth::forgetGuards();
        $this->withToken($operatorToken)->getJson('/api/v1/me')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($adminToken)->patchJson("/api/v1/users/{$admin->id}", ['status' => 'disabled'])->assertUnprocessable();
    }

    public function test_project_api_key_is_hashed_scoped_and_revocable(): void
    {
        $user = User::factory()->create();
        $project = Project::query()->create(['owner_id' => $user->id, 'name' => 'Gateway']);
        $otherUser = User::factory()->create();
        $otherProject = Project::query()->create(['owner_id' => $otherUser->id, 'name' => 'Private']);
        $token = $user->createToken('tests')->plainTextToken;

        $created = $this->withToken($token)->postJson("/api/v1/projects/{$project->id}/api-keys", ['name' => 'integration']);
        $created->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->assertJsonStructure(['secret', 'data' => ['id', 'prefix']]);
        $secret = $created->json('secret');
        $stored = ApiKey::query()->firstOrFail();
        $this->assertSame(hash('sha256', $secret), $stored->key_hash);
        $this->assertDatabaseHas('audit_logs', ['action' => 'api_key.created', 'subject_id' => (string) $stored->id]);
        $this->assertStringNotContainsString($secret, json_encode($stored->getAttributes(), JSON_THROW_ON_ERROR));

        $this->withHeaders(['X-API-Key' => $secret, 'Idempotency-Key' => 'keytest-00001'])->postJson('/api/v1/messages', [
            'to' => '+967711234567', 'body' => 'hello gateway',
        ])->assertAccepted();

        $this->withToken($token)->postJson("/api/v1/projects/{$otherProject->id}/api-keys", ['name' => 'forbidden'])->assertNotFound();
        $this->withToken($token)->deleteJson("/api/v1/projects/{$project->id}/api-keys/{$stored->id}")->assertOk();
        $this->withHeaders(['X-API-Key' => $secret, 'Idempotency-Key' => 'keytest-00002'])->postJson('/api/v1/messages', [
            'to' => '+967711234567', 'body' => 'hello gateway',
        ])->assertUnauthorized();
    }

    public function test_message_submission_is_encrypted_idempotent_and_transactionally_outboxed(): void
    {
        $project = $this->projectWithKey();
        $payload = ['to' => '+967711234567', 'body' => 'مرحبا'];
        $headers = ['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'request-000001'];

        $accepted = $this->withHeaders($headers)->postJson('/api/v1/messages', $payload)->assertAccepted()->assertJsonPath('data.status', 'queued');
        $messageId = $accepted->json('data.id');
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('message_events', 1);
        $this->assertDatabaseCount('outbox_events', 1);
        $row = DB::table('messages')->first();
        $this->assertStringNotContainsString($payload['to'], $row->destination);
        $this->assertStringNotContainsString($payload['body'], $row->body);

        $this->withHeaders($headers)->postJson('/api/v1/messages', $payload)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->withHeaders($headers)->postJson('/api/v1/messages', ['to' => '+967711234567', 'body' => 'different'])->assertConflict();
        $otherProject = $this->projectWithKey();
        $this->withHeaders(['X-API-Key' => $otherProject['secret']])->getJson("/api/v1/messages/{$messageId}")->assertNotFound();

        $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'short'])->postJson('/api/v1/messages', $payload)->assertUnprocessable();
    }

    public function test_project_daily_limit_is_transactional_and_duplicate_requests_do_not_consume_quota(): void
    {
        $project = $this->projectWithKey(2);
        $headers = ['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'limit-req-0001'];
        $payload = ['to' => '+967711234567', 'body' => 'first'];

        $this->withHeaders($headers)->postJson('/api/v1/messages', $payload)->assertAccepted();
        $this->withHeaders($headers)->postJson('/api/v1/messages', $payload)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseHas('project_daily_usage', ['project_id' => $project['project']->id, 'message_count' => 1]);

        $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'limit-req-0002'])
            ->postJson('/api/v1/messages', ['to' => '+967711234567', 'body' => 'second'])->assertAccepted();
        $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'limit-req-0003'])
            ->postJson('/api/v1/messages', ['to' => '+967711234567', 'body' => 'third'])->assertTooManyRequests();

        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseCount('outbox_events', 2);
        $this->assertDatabaseHas('project_daily_usage', ['project_id' => $project['project']->id, 'message_count' => 2]);
    }

    public function test_device_claim_acknowledge_and_status_reporting_are_scoped_and_auditable(): void
    {
        Queue::fake();
        $project = $this->projectWithKey();
        $deviceSecret = 'azm_device_'.str_repeat('d', 48);
        $device = Device::query()->create([
            'project_id' => $project['project']->id,
            'name' => 'Android gateway',
            'token_prefix' => substr($deviceSecret, 0, 24),
            'token_hash' => hash('sha256', $deviceSecret),
        ]);
        $messageId = $this->withHeaders([
            'X-API-Key' => $project['secret'],
            'Idempotency-Key' => 'device-claim-0001',
        ])->postJson('/api/v1/messages', ['to' => '+967711234567', 'body' => 'test'])->json('data.id');

        $deviceHeaders = ['Authorization' => "Bearer {$deviceSecret}"];
        $this->withHeaders($deviceHeaders)->postJson('/api/v1/devices/heartbeat', [
            'app_version' => '1.0.0', 'android_version' => '17', 'api_level' => 37,
            'network_status' => 'connected', 'sim_status' => 'ready',
        ])->assertOk()->assertJsonPath('accepted', true);

        $claim = $this->withHeaders($deviceHeaders)->getJson('/api/v1/devices/messages/claim');
        $claim->assertOk()->assertJsonPath('data.id', $messageId)->assertJsonPath('data.body', 'test');
        $this->withHeaders($deviceHeaders)->getJson('/api/v1/devices/messages/claim')->assertJsonPath('data.id', $messageId);
        $this->withHeaders($deviceHeaders)->postJson("/api/v1/devices/messages/{$messageId}/acknowledge")->assertOk()->assertJsonPath('data.status', 'accepted_by_device');
        $this->withHeaders($deviceHeaders)->postJson("/api/v1/devices/messages/{$messageId}/report", ['status' => 'sending'])->assertOk()->assertJsonPath('data.status', 'sending');
        $this->withHeaders($deviceHeaders)->postJson("/api/v1/devices/messages/{$messageId}/report", ['status' => 'sent_to_network'])->assertOk()->assertJsonPath('data.status', 'sent_to_network');

        $this->assertSame(MessageStatus::SentToNetwork, Message::query()->findOrFail($messageId)->status);
        $this->assertSame(1, Message::query()->findOrFail($messageId)->attempt_count);
        $this->withHeaders($deviceHeaders)->postJson("/api/v1/devices/messages/{$messageId}/report", ['status' => 'unknown'])->assertOk()->assertJsonPath('data.status', 'unknown');
        $this->withHeaders($deviceHeaders)->getJson('/api/v1/devices/messages/claim')->assertJsonPath('data', null);
        $this->assertDatabaseCount('message_events', 6); // accepted, assigned, acknowledged, sending, network hand-off, unresolved outcome
        $this->assertTrue($device->fresh()->last_heartbeat_at->greaterThan(now()->subMinute()));
    }

    public function test_message_state_machine_rejects_unsafe_transition(): void
    {
        $project = $this->projectWithKey();
        $messageId = $this->withHeaders([
            'X-API-Key' => $project['secret'], 'Idempotency-Key' => 'transition-00001',
        ])->postJson('/api/v1/messages', ['to' => '+967711234567', 'body' => 'test'])->json('data.id');
        $deviceSecret = 'azm_device_'.str_repeat('z', 48);
        Device::query()->create([
            'project_id' => $project['project']->id, 'name' => 'test',
            'token_prefix' => substr($deviceSecret, 0, 24), 'token_hash' => hash('sha256', $deviceSecret),
        ]);

        $headers = ['Authorization' => "Bearer {$deviceSecret}"];
        $this->withHeaders($headers)->getJson('/api/v1/devices/messages/claim')->assertOk();
        $this->withHeaders($headers)->postJson("/api/v1/devices/messages/{$messageId}/report", ['status' => 'sent_to_network'])->assertUnprocessable();
    }

    public function test_carrier_delivery_report_can_complete_message_from_device_accepted_state(): void
    {
        $project = $this->projectWithKey();
        $deviceSecret = 'azm_device_'.str_repeat('r', 48);
        $device = Device::query()->create([
            'project_id' => $project['project']->id,
            'name' => 'delivery-report-test',
            'token_prefix' => substr($deviceSecret, 0, 24),
            'token_hash' => hash('sha256', $deviceSecret),
        ]);
        $messageId = $this->withHeaders([
            'X-API-Key' => $project['secret'],
            'Idempotency-Key' => 'delivery-report-0001',
        ])->postJson('/api/v1/messages', ['to' => '+967711234567', 'body' => 'test'])->json('data.id');

        $deviceHeaders = ['Authorization' => "Bearer {$deviceSecret}"];
        $this->withHeaders($deviceHeaders)->getJson('/api/v1/devices/messages/claim')->assertOk();
        $this->withHeaders($deviceHeaders)->postJson("/api/v1/devices/messages/{$messageId}/acknowledge")
            ->assertOk()->assertJsonPath('data.status', 'accepted_by_device');
        $this->withHeaders($deviceHeaders)->postJson("/api/v1/devices/messages/{$messageId}/report", ['status' => 'delivery_reported'])
            ->assertOk()->assertJsonPath('data.status', 'delivery_reported');

        $this->assertSame(MessageStatus::DeliveryReported, Message::query()->findOrFail($messageId)->status);
        $this->assertNotNull(Message::query()->findOrFail($messageId)->completed_at);
        $this->assertDatabaseHas('message_events', [
            'message_id' => $messageId,
            'event_type' => 'device.status_reported',
            'from_status' => 'accepted_by_device',
            'to_status' => 'delivery_reported',
            'actor_id' => (string) $device->id,
        ]);
    }

    public function test_api_refuses_plain_http_when_running_in_production_mode(): void
    {
        config(['app.env' => 'production']);
        $this->postJson('/api/v1/auth/login', [
            'email' => 'operator@example.test', 'password' => 'long-enough-password', 'device_name' => 'test',
        ])->assertBadRequest()->assertJsonPath('message', 'HTTPS is required.');
    }

    public function test_outbox_dispatches_committed_event_and_processing_is_idempotent(): void
    {
        Queue::fake();
        $event = OutboxEvent::query()->create([
            'id' => (string) Str::uuid(), 'aggregate_type' => 'message', 'aggregate_id' => 'stable-id',
            'event_type' => 'message.accepted', 'payload' => ['message_id' => 'stable-id'],
            'available_at' => now(),
        ]);

        $this->artisan('outbox:dispatch')->assertSuccessful();
        Queue::assertPushed(ProcessOutboxEvent::class, fn (ProcessOutboxEvent $job): bool => $job->eventId === $event->id);
        $event->refresh();
        $this->assertSame(1, $event->attempts);
        $this->assertNotNull($event->lease_expires_at);

        $job = new ProcessOutboxEvent($event->id);
        $job->handle();
        $job->handle();
        $this->assertNotNull($event->fresh()->processed_at);
        $this->assertNull($event->fresh()->lease_expires_at);
    }

    public function test_outbox_does_not_dispatch_not_yet_available_event(): void
    {
        Queue::fake();
        $event = OutboxEvent::query()->create([
            'id' => (string) Str::uuid(), 'aggregate_type' => 'message', 'aggregate_id' => 'future-id',
            'event_type' => 'message.accepted', 'payload' => ['message_id' => 'future-id'],
            'available_at' => now()->addHour(),
        ]);

        $this->artisan('outbox:dispatch')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->assertSame(0, $event->fresh()->attempts);
    }

    /** @return array{project: Project, secret: string} */
    private function projectWithKey(int $dailyMessageLimit = 20): array
    {
        $user = User::factory()->create();
        $project = Project::query()->create(['owner_id' => $user->id, 'name' => 'Gateway', 'daily_message_limit' => $dailyMessageLimit]);
        $secret = 'azm_live_'.Str::random(48);
        ApiKey::query()->create([
            'project_id' => $project->id, 'name' => 'test key',
            'prefix' => substr($secret, 0, 24), 'key_hash' => hash('sha256', $secret),
        ]);

        return ['project' => $project, 'secret' => $secret];
    }
}
