<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiKeyQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_key_daily_limit_blocks_new_messages_and_replay_does_not_consume_quota(): void
    {
        $project = $this->projectWithKey(keyDaily: 1, projectDaily: 10);
        $payload = ['to' => '+967711234567', 'body' => 'first'];
        $headers = ['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'quota-key-0001'];

        $this->withHeaders($headers)->postJson('/api/v1/messages', $payload)->assertAccepted();
        $this->withHeaders($headers)->postJson('/api/v1/messages', $payload)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertDatabaseHas('api_key_daily_usage', ['api_key_id' => $project['key']->id, 'message_count' => 1]);
        $this->assertDatabaseHas('project_daily_usage', ['project_id' => $project['project']->id, 'message_count' => 1]);

        $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'quota-key-0002'])
            ->postJson('/api/v1/messages', ['to' => '+967711234567', 'body' => 'second'])
            ->assertTooManyRequests();
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('api_key_daily_usage', ['api_key_id' => $project['key']->id, 'message_count' => 1]);
    }

    public function test_project_ceiling_can_be_tighter_than_the_key_limit(): void
    {
        $project = $this->projectWithKey(keyDaily: 10, projectDaily: 1);
        $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'ceiling-000001'])
            ->postJson('/api/v1/messages', ['to' => '+967711234567', 'body' => 'one'])
            ->assertAccepted();
        $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'ceiling-000002'])
            ->postJson('/api/v1/messages', ['to' => '+967711234567', 'body' => 'two'])
            ->assertTooManyRequests();
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('project_daily_usage', ['project_id' => $project['project']->id, 'message_count' => 1]);
    }

    public function test_per_minute_limit_is_per_key_and_ignores_idempotent_replay(): void
    {
        $project = $this->projectWithKey(keyDaily: 10, projectDaily: 10, perMinute: 1);
        $headers = ['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'minute-0000001'];
        $payload = ['to' => '+967711234567', 'body' => 'paced'];

        $this->withHeaders($headers)->postJson('/api/v1/messages', $payload)->assertAccepted();
        $this->withHeaders($headers)->postJson('/api/v1/messages', $payload)->assertOk()->assertJsonPath('duplicate', true);
        $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'minute-0000002'])
            ->postJson('/api/v1/messages', ['to' => '+967711234567', 'body' => 'again'])
            ->assertTooManyRequests();
        $this->assertSame(1, (int) $project['key']->dailyUsage()->first()?->message_count);

        $this->travel(61)->seconds();
        $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'minute-0000003'])
            ->postJson('/api/v1/messages', ['to' => '+967711234567', 'body' => 'later'])
            ->assertAccepted();
        $this->assertSame(2, (int) $project['key']->fresh()->dailyUsage()->sum('message_count'));
    }

    public function test_admin_quota_edit_is_audited_without_the_secret(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $project = Project::query()->create(['owner_id' => $admin->id, 'name' => 'Quota project', 'daily_message_limit' => 40]);
        $secret = 'azm_live_'.Str::random(48);
        $key = ApiKey::query()->create([
            'project_id' => $project->id,
            'name' => 'site',
            'prefix' => substr($secret, 0, 24),
            'key_hash' => hash('sha256', $secret),
            'daily_message_limit' => 20,
            'requests_per_minute' => 10,
        ]);

        $this->actingAs($admin, 'web')->patch(route('admin.keys.update', [$project, $key]), [
            'name' => 'site',
            'daily_message_limit' => 15,
            'requests_per_minute' => 4,
        ])->assertRedirect();

        $key->refresh();
        $this->assertSame(15, $key->daily_message_limit);
        $this->assertSame(4, $key->requests_per_minute);
        $log = AuditLog::query()->where('action', 'api_key.quota_updated')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame((string) $key->id, $log->subject_id);
        $this->assertSame(20, $log->metadata['before']['daily_message_limit']);
        $this->assertSame(15, $log->metadata['after']['daily_message_limit']);
        $encoded = json_encode($log->metadata, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString('message body', $encoded);

        $this->actingAs($admin, 'web')->get(route('admin.keys'))->assertOk()->assertSee('الحصة الفعالة')->assertDontSee($secret);
        $this->patch(route('admin.keys.update', [$project, $key]), [
            'name' => 'site',
            'daily_message_limit' => 5000,
            'requests_per_minute' => 4,
        ])->assertSessionHasErrors('daily_message_limit');
    }

    /**
     * @return array{project: Project, key: ApiKey, secret: string}
     */
    private function projectWithKey(int $keyDaily, int $projectDaily, int $perMinute = 10): array
    {
        $user = User::factory()->create();
        $project = Project::query()->create([
            'owner_id' => $user->id,
            'name' => 'Quota',
            'daily_message_limit' => $projectDaily,
        ]);
        $secret = 'azm_live_'.Str::random(48);
        $key = ApiKey::query()->create([
            'project_id' => $project->id,
            'name' => 'limited',
            'prefix' => substr($secret, 0, 24),
            'key_hash' => hash('sha256', $secret),
            'daily_message_limit' => $keyDaily,
            'requests_per_minute' => $perMinute,
        ]);

        return ['project' => $project, 'key' => $key, 'secret' => $secret];
    }
}
