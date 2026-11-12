<?php

namespace Tests\Feature;

use App\Enums\MessageStatus;
use App\Models\ApiKey;
use App\Models\Device;
use App\Models\Message;
use App\Models\OtpChallenge;
use App\Models\Project;
use App\Models\User;
use App\Services\OtpMessageRecall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OtpWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_before_claim_recalls_the_message_without_exposing_the_code(): void
    {
        $project = $this->enabledProject();
        $phone = '+967711234567';
        $request = $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'otp-can-000001'])
            ->postJson('/api/v1/otp/request', ['to' => $phone]);
        $request->assertAccepted();
        $challengeId = (string) $request->json('data.id');
        $code = $this->codeFromMessage(Message::query()->firstOrFail());

        $cancel = $this->withHeaders(['X-API-Key' => $project['secret']])
            ->postJson("/api/v1/otp/{$challengeId}/cancel");
        $cancel->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.message_recalled', true);
        $this->assertNotContains($code, $this->leafValues($cancel->json()));

        $message = Message::query()->firstOrFail();
        $this->assertSame(MessageStatus::Cancelled, $message->status);
        $this->assertSame(OtpMessageRecall::RECALLED_BODY, $message->body);
        $this->assertSame('suppressed:otp_not_sendable', DB::table('outbox_events')->value('last_error'));
        $this->assertNotNull(DB::table('outbox_events')->value('processed_at'));

        $this->withHeaders(['Authorization' => 'Bearer '.$project['device_secret']])
            ->getJson('/api/v1/devices/messages/claim')
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->withHeaders(['X-API-Key' => $project['secret']])
            ->postJson("/api/v1/otp/{$challengeId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.message_recalled', true);
    }

    public function test_new_otp_supersedes_the_previous_challenge_and_message_before_claim(): void
    {
        $project = $this->enabledProject(cooldown: 1);
        $phone = '+967711234567';

        $first = $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'otp-sup-000001'])
            ->postJson('/api/v1/otp/request', ['to' => $phone])
            ->assertAccepted();
        $firstMessageId = (string) $first->json('data.message_id');
        $firstCode = $this->codeFromMessage(Message::query()->findOrFail($firstMessageId));

        $this->travel(2)->seconds();
        $second = $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'otp-sup-000002'])
            ->postJson('/api/v1/otp/request', ['to' => $phone])
            ->assertAccepted();

        $firstMessage = Message::query()->findOrFail($firstMessageId);
        $this->assertSame(MessageStatus::Cancelled, $firstMessage->status);
        $this->assertSame('superseded', OtpChallenge::query()->where('message_id', $firstMessageId)->value('status'));
        $this->assertNotSame($firstCode, $this->codeFromMessage(Message::query()->findOrFail($second->json('data.message_id'))));

        $claim = $this->withHeaders(['Authorization' => 'Bearer '.$project['device_secret']])
            ->getJson('/api/v1/devices/messages/claim')
            ->assertOk();
        $this->assertSame($second->json('data.message_id'), $claim->json('data.id'));
        $this->assertTrue($claim->json('data.send_authorization_required'));
        $this->assertStringNotContainsString($firstCode, (string) $claim->json('data.body'));
    }

    public function test_expired_otp_is_not_claimable_and_send_authorization_rejects_it(): void
    {
        $project = $this->enabledProject(ttl: 30);
        $phone = '+967711234567';
        $response = $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'otp-exp-000001'])
            ->postJson('/api/v1/otp/request', ['to' => $phone])
            ->assertAccepted();
        $messageId = (string) $response->json('data.message_id');
        $code = $this->codeFromMessage(Message::query()->findOrFail($messageId));

        $this->travel(31)->seconds();
        $this->withHeaders(['Authorization' => 'Bearer '.$project['device_secret']])
            ->getJson('/api/v1/devices/messages/claim')
            ->assertOk()
            ->assertJsonPath('data', null);

        $message = Message::query()->findOrFail($messageId);
        $this->assertSame(MessageStatus::Cancelled, $message->status);
        $this->assertSame('expired', OtpChallenge::query()->where('message_id', $messageId)->value('status'));
        $this->assertStringNotContainsString($code, (string) $message->body);

        $message->forceFill(['device_id' => Device::query()->firstOrFail()->id, 'status' => MessageStatus::AcceptedByDevice])->save();
        $auth = $this->withHeaders(['Authorization' => 'Bearer '.$project['device_secret']])
            ->postJson("/api/v1/devices/messages/{$messageId}/send-authorization");
        $auth->assertOk()
            ->assertJsonPath('data.send', false)
            ->assertJsonPath('data.reason', 'expired');
    }

    public function test_send_authorization_reserves_sending_before_cancellation_can_recall(): void
    {
        $project = $this->enabledProject();
        $request = $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'otp-race-000001'])
            ->postJson('/api/v1/otp/request', ['to' => '+967711234567'])
            ->assertAccepted();
        $challengeId = (string) $request->json('data.id');
        $messageId = (string) $request->json('data.message_id');
        $deviceHeaders = ['Authorization' => 'Bearer '.$project['device_secret']];

        $this->withHeaders($deviceHeaders)->getJson('/api/v1/devices/messages/claim')->assertOk();
        $this->withHeaders($deviceHeaders)->postJson("/api/v1/devices/messages/{$messageId}/acknowledge")->assertOk();
        $this->withHeaders($deviceHeaders)
            ->postJson("/api/v1/devices/messages/{$messageId}/send-authorization")
            ->assertOk()
            ->assertJsonPath('data.send', true);

        // A repeated authorization call must not reserve or authorize the same OTP twice.
        $this->withHeaders($deviceHeaders)
            ->postJson("/api/v1/devices/messages/{$messageId}/send-authorization")
            ->assertOk()
            ->assertJsonPath('data.send', false)
            ->assertJsonPath('data.reason', 'already_started');

        $this->assertSame(MessageStatus::Sending, Message::query()->findOrFail($messageId)->status);

        $cancel = $this->withHeaders(['X-API-Key' => $project['secret']])
            ->postJson("/api/v1/otp/{$challengeId}/cancel");
        $cancel->assertStatus(409)->assertJsonPath('data.message_recalled', false);
        $this->assertSame(MessageStatus::Sending, Message::query()->findOrFail($messageId)->status);
        $this->assertSame('cancelled', OtpChallenge::query()->findOrFail($challengeId)->status);
    }

    public function test_cancellation_before_send_authorization_prevents_the_send_reservation(): void
    {
        $project = $this->enabledProject();
        $request = $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'otp-race-000002'])
            ->postJson('/api/v1/otp/request', ['to' => '+967711234567'])
            ->assertAccepted();
        $challengeId = (string) $request->json('data.id');
        $messageId = (string) $request->json('data.message_id');
        $deviceHeaders = ['Authorization' => 'Bearer '.$project['device_secret']];

        $this->withHeaders($deviceHeaders)->getJson('/api/v1/devices/messages/claim')->assertOk();
        $this->withHeaders($deviceHeaders)->postJson("/api/v1/devices/messages/{$messageId}/acknowledge")->assertOk();
        $this->withHeaders(['X-API-Key' => $project['secret']])
            ->postJson("/api/v1/otp/{$challengeId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.message_recalled', true);

        $this->withHeaders($deviceHeaders)
            ->postJson("/api/v1/devices/messages/{$messageId}/send-authorization")
            ->assertOk()
            ->assertJsonPath('data.send', false)
            ->assertJsonPath('data.reason', 'cancelled');
        $this->assertSame(MessageStatus::Cancelled, Message::query()->findOrFail($messageId)->status);
    }

    public function test_sent_to_network_otp_cannot_be_recalled_via_cancel(): void
    {
        $project = $this->enabledProject();
        $phone = '+967711234567';
        $response = $this->withHeaders(['X-API-Key' => $project['secret'], 'Idempotency-Key' => 'otp-net-00001'])
            ->postJson('/api/v1/otp/request', ['to' => $phone])
            ->assertAccepted();
        $challengeId = (string) $response->json('data.id');
        $messageId = (string) $response->json('data.message_id');
        $code = $this->codeFromMessage(Message::query()->findOrFail($messageId));

        $device = Device::query()->firstOrFail();
        Message::query()->whereKey($messageId)->update([
            'device_id' => $device->id,
            'status' => MessageStatus::SentToNetwork->value,
        ]);

        $cancel = $this->withHeaders(['X-API-Key' => $project['secret']])
            ->postJson("/api/v1/otp/{$challengeId}/cancel");
        $cancel->assertStatus(409)
            ->assertJsonPath('data.message_recalled', false)
            ->assertJsonPath('data.status', 'cancelled');
        $this->assertNotContains($code, $this->leafValues($cancel->json()));
        $this->assertSame(MessageStatus::SentToNetwork, Message::query()->findOrFail($messageId)->status);
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
     * @return array{project: Project, secret: string, device_secret: string}
     */
    private function enabledProject(int $ttl = 300, int $cooldown = 60): array
    {
        $owner = User::factory()->create();
        $project = Project::query()->create([
            'owner_id' => $owner->id,
            'name' => 'OTP withdrawal '.Str::lower(Str::random(6)),
            'daily_message_limit' => 20,
            'otp_enabled' => true,
            'otp_ttl_seconds' => $ttl,
            'otp_max_attempts' => 5,
            'otp_resend_cooldown_seconds' => $cooldown,
        ]);
        $secret = 'azm_live_'.Str::random(48);
        ApiKey::query()->create([
            'project_id' => $project->id,
            'name' => 'otp',
            'prefix' => substr($secret, 0, 24),
            'key_hash' => hash('sha256', $secret),
            'daily_message_limit' => 20,
            'requests_per_minute' => 10,
        ]);
        $deviceSecret = 'azm_device_'.Str::random(48);
        Device::query()->create([
            'project_id' => $project->id,
            'name' => 'gateway',
            'token_prefix' => substr($deviceSecret, 0, 24),
            'token_hash' => hash('sha256', $deviceSecret),
        ]);

        return ['project' => $project, 'secret' => $secret, 'device_secret' => $deviceSecret];
    }
}
