<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\Message;
use App\Models\OtpChallenge;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OtpApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_otp_is_disabled_by_default_and_a_rejected_request_leaves_no_message(): void
    {
        $project = $this->projectWithKey();
        $this->assertFalse($project['project']->otp_enabled);

        $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'otp-off-000001'])
            ->postJson('/api/v1/otp/request', ['to' => '+967711234567'])
            ->assertForbidden();

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('otp_challenges', 0);
        $this->assertDatabaseCount('api_key_daily_usage', 0);
    }

    public function test_otp_request_uses_the_message_path_and_never_returns_or_stores_the_code(): void
    {
        $project = $this->projectWithKey(enabled: true);
        $phone = '+967711234567';
        $response = $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'otp-req-000001'])
            ->postJson('/api/v1/otp/request', ['to' => $phone, 'purpose' => 'login']);

        $response->assertAccepted()->assertJsonPath('duplicate', false)->assertJsonPath('data.status', 'pending');
        $code = $this->codeFromMessage(Message::query()->firstOrFail());
        $this->assertNotContains($code, $this->leafValues($response->json()));
        $this->assertNotContains($phone, $this->leafValues($response->json()));

        $challenge = DB::table('otp_challenges')->first();
        $message = DB::table('messages')->first();
        $this->assertNotNull($challenge);
        $this->assertSame($message->id, $challenge->message_id);
        $this->assertSame(OtpChallenge::hashCode($code), $challenge->code_hash);
        $this->assertNotSame($code, $challenge->code_hash);
        $this->assertSame(OtpChallenge::maskPhone($phone), $challenge->phone_mask);
        $this->assertNotSame($phone, $challenge->phone_mask);
        $this->assertStringNotContainsString($phone, (string) $challenge->phone_hash);
        $outboxPayload = json_decode((string) DB::table('outbox_events')->value('payload'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('body', $outboxPayload);
        $this->assertArrayNotHasKey('code', $outboxPayload);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertDatabaseHas('api_key_daily_usage', ['api_key_id' => $project['key']->id, 'message_count' => 1]);

        $replay = $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'otp-req-000001'])
            ->postJson('/api/v1/otp/request', ['to' => $phone, 'purpose' => 'login']);
        $replay->assertOk()->assertJsonPath('duplicate', true);
        $this->assertNotContains($code, $this->leafValues($replay->json()));
        $this->assertDatabaseCount('otp_challenges', 1);
        $this->assertDatabaseHas('api_key_daily_usage', ['api_key_id' => $project['key']->id, 'message_count' => 1]);

        $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'otp-req-000001'])
            ->postJson('/api/v1/otp/request', ['to' => '+967700000099', 'purpose' => 'login'])
            ->assertConflict();
    }

    public function test_verification_enforces_expiry_attempts_phone_and_project_without_extra_quota(): void
    {
        $project = $this->projectWithKey(enabled: true, attempts: 2, ttl: 60);
        $phone = '+967711234567';
        $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'otp-ver-000001'])
            ->postJson('/api/v1/otp/request', ['to' => $phone])
            ->assertAccepted();
        $code = $this->codeFromMessage(Message::query()->firstOrFail());

        $deviceSecret = 'azm_device_'.str_repeat('o', 48);
        Device::query()->create([
            'project_id' => $project['project']->id,
            'name' => 'gateway',
            'token_prefix' => substr($deviceSecret, 0, 24),
            'token_hash' => hash('sha256', $deviceSecret),
        ]);
        $this->withHeaders(['Authorization' => 'Bearer '.$deviceSecret])
            ->getJson('/api/v1/devices/messages/claim')
            ->assertOk()
            ->assertJsonPath('data.body', 'رمز التحقق: '.$code);

        $this->withHeaders(['X-API-Key' => $project['secret']])
            ->postJson('/api/v1/otp/verify', ['to' => $phone, 'code' => '000000'])
            ->assertUnprocessable()
            ->assertJsonPath('data.verified', false);
        $this->assertSame(1, OtpChallenge::query()->firstOrFail()->attempts);
        $this->assertDatabaseHas('api_key_daily_usage', ['api_key_id' => $project['key']->id, 'message_count' => 1]);

        $other = $this->projectWithKey(enabled: true);
        $this->withHeaders(['X-API-Key' => $other['secret']])
            ->postJson('/api/v1/otp/verify', ['to' => $phone, 'code' => $code])
            ->assertUnprocessable()
            ->assertJsonPath('data.status', 'invalid');

        $this->withHeaders(['X-API-Key' => $project['secret']])
            ->postJson('/api/v1/otp/verify', ['to' => '+967700000099', 'code' => $code])
            ->assertUnprocessable();

        $this->travel(61)->seconds();
        $this->withHeaders(['X-API-Key' => $project['secret']])
            ->postJson('/api/v1/otp/verify', ['to' => $phone, 'code' => $code])
            ->assertUnprocessable()
            ->assertJsonPath('data.status', 'expired');
        $this->assertSame('expired', OtpChallenge::query()->first()->status);
    }

    public function test_resend_cooldown_and_attempt_limit_do_not_leave_an_unchallenged_message(): void
    {
        $project = $this->projectWithKey(enabled: true, attempts: 1, cooldown: 60);
        $phone = '+967711234567';
        $first = $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'otp-cool-00001'])
            ->postJson('/api/v1/otp/request', ['to' => $phone])
            ->assertAccepted();
        $firstCode = $this->codeFromMessage(Message::query()->findOrFail($first->json('data.message_id')));

        $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'otp-cool-00002'])
            ->postJson('/api/v1/otp/request', ['to' => $phone])
            ->assertTooManyRequests();
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('otp_challenges', 1);

        $this->withHeaders(['X-API-Key' => $project['secret']])
            ->postJson('/api/v1/otp/verify', ['to' => $phone, 'code' => '111111'])
            ->assertUnprocessable()
            ->assertJsonPath('data.status', 'exhausted');
        $this->withHeaders(['X-API-Key' => $project['secret']])
            ->postJson('/api/v1/otp/verify', ['to' => $phone, 'code' => $firstCode])
            ->assertUnprocessable();

        $limited = $this->projectWithKey(enabled: true, keyDaily: 1);
        $this->withHeaders(['X-API-Key' => $limited['secret'], 'Idempotency-Key' => 'otp-cap-000001'])
            ->postJson('/api/v1/otp/request', ['to' => '+967711234567'])
            ->assertAccepted();
        $this->withHeaders(['X-API-Key' => $limited['secret'], 'Idempotency-Key' => 'otp-cap-000002'])
            ->postJson('/api/v1/otp/request', ['to' => '+967700000088'])
            ->assertTooManyRequests();
        $this->assertSame(1, OtpChallenge::query()->where('project_id', $limited['project']->id)->count());
        $this->assertSame(1, Message::query()->where('project_id', $limited['project']->id)->count());
    }

    public function test_admin_otp_page_masks_the_phone_and_hides_the_code(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $project = $this->projectWithKey(enabled: true, owner: $admin);
        $phone = '+967711234567';
        $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'otp-ui-0000001'])
            ->postJson('/api/v1/otp/request', ['to' => $phone])
            ->assertAccepted();
        $code = $this->codeFromMessage(Message::query()->firstOrFail());

        $this->actingAs($admin, 'web')->get(route('admin.otp'))
            ->assertOk()
            ->assertSee(OtpChallenge::maskPhone($phone))
            ->assertSee('بانتظار التحقق')
            ->assertDontSee($phone)
            ->assertDontSee($code)
            ->assertDontSee('رمز التحقق');

        $this->patch(route('admin.otp.update', $project['project']), [
            'otp_enabled' => '0',
            'otp_ttl_seconds' => 120,
            'otp_max_attempts' => 3,
            'otp_resend_cooldown_seconds' => 90,
        ])->assertRedirect();
        $this->assertFalse($project['project']->fresh()->otp_enabled);
        $log = DB::table('audit_logs')->where('action', 'otp.settings_updated')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString($phone, (string) $log->metadata);
        $this->assertStringNotContainsString($code, (string) $log->metadata);
    }

    /**
     * @return list<mixed>
     */
    private function leafValues(mixed $value): array
    {
        if (! is_array($value)) {
            return [$value];
        }

        $leaves = [];
        foreach ($value as $item) {
            $leaves = array_merge($leaves, $this->leafValues($item));
        }

        return $leaves;
    }

    private function codeFromMessage(Message $message): string
    {
        $this->assertSame(1, preg_match('/(\d{6})/', $message->body, $matches));

        return $matches[1];
    }

    /**
     * @return array{project: Project, key: ApiKey, secret: string}
     */
    private function projectWithKey(
        bool $enabled = false,
        int $attempts = 5,
        int $ttl = 300,
        int $cooldown = 60,
        int $keyDaily = 20,
        ?User $owner = null,
    ): array {
        $owner ??= User::factory()->create();
        $project = Project::query()->create([
            'owner_id' => $owner->id,
            'name' => 'OTP '.$owner->id.'-'.Str::lower(Str::random(4)),
            'daily_message_limit' => 20,
            'otp_enabled' => $enabled,
            'otp_ttl_seconds' => $ttl,
            'otp_max_attempts' => $attempts,
            'otp_resend_cooldown_seconds' => $cooldown,
        ]);
        $secret = 'azm_live_'.Str::random(48);
        $key = ApiKey::query()->create([
            'project_id' => $project->id,
            'name' => 'otp',
            'prefix' => substr($secret, 0, 24),
            'key_hash' => hash('sha256', $secret),
            'daily_message_limit' => $keyDaily,
            'requests_per_minute' => 10,
        ]);

        return ['project' => $project, 'key' => $key, 'secret' => $secret];
    }
}
