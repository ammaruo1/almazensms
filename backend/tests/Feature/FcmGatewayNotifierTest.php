<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\Project;
use App\Models\User;
use App\Services\FcmGatewayNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FcmGatewayNotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_notifier_skips_when_credentials_are_missing(): void
    {
        config(['services.firebase.credentials' => null]);
        Http::fake();

        app(FcmGatewayNotifier::class)->notifyProject(1, (string) Str::uuid());

        Http::assertNothingSent();
    }

    public function test_notifier_sends_only_the_message_id_at_high_priority(): void
    {
        $path = $this->serviceAccountPath();
        config(['services.firebase.credentials' => $path]);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/almazen-test/messages/1']),
        ]);

        $user = User::factory()->create();
        $project = Project::query()->create(['owner_id' => $user->id, 'name' => 'OTP']);
        Device::query()->create([
            'project_id' => $project->id,
            'name' => 'Handset',
            'token_prefix' => 'azm_device_fcm_prefix01',
            'token_hash' => hash('sha256', 'azm_device_'.str_repeat('a', 40)),
            'fcm_token' => 'device-fcm-token',
        ]);
        $messageId = (string) Str::uuid();

        app(FcmGatewayNotifier::class)->notifyProject($project->id, $messageId);

        Http::assertSent(function ($request) use ($messageId): bool {
            if (! str_contains($request->url(), 'messages:send')) {
                return false;
            }
            $payload = $request->data();

            return ($payload['message']['data']['message_id'] ?? null) === $messageId
                && ($payload['message']['android']['priority'] ?? null) === 'HIGH'
                && ($payload['message']['token'] ?? null) === 'device-fcm-token'
                && ! array_key_exists('body', $payload['message']['data'] ?? []);
        });
    }

    public function test_heartbeat_stores_the_push_token_outside_metadata(): void
    {
        $user = User::factory()->create();
        $project = Project::query()->create(['owner_id' => $user->id, 'name' => 'OTP']);
        $secret = 'azm_live_'.Str::random(48);
        ApiKey::query()->create([
            'project_id' => $project->id,
            'name' => 'key',
            'prefix' => substr($secret, 0, 24),
            'key_hash' => hash('sha256', $secret),
        ]);
        $deviceSecret = 'azm_device_'.str_repeat('b', 40);
        $device = Device::query()->create([
            'project_id' => $project->id,
            'name' => 'Handset',
            'token_prefix' => substr($deviceSecret, 0, 24),
            'token_hash' => hash('sha256', $deviceSecret),
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$deviceSecret])
            ->postJson('/api/v1/devices/heartbeat', [
                'app_version' => '1.0.0',
                'android_version' => '17',
                'api_level' => 37,
                'network_status' => 'connected',
                'sim_status' => 'ready',
                'fcm_token' => 'stored-push-token',
            ])
            ->assertOk();

        $fresh = $device->fresh();
        $this->assertSame('stored-push-token', $fresh->fcm_token);
        $this->assertArrayNotHasKey('fcm_token', $fresh->metadata ?? []);
    }

    public function test_devices_page_marks_a_stale_heartbeat_offline(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $project = Project::query()->create(['owner_id' => $admin->id, 'name' => 'Gate']);
        Device::query()->create([
            'project_id' => $project->id,
            'name' => 'Desk phone',
            'token_prefix' => 'azm_device_stale_prefix1',
            'token_hash' => hash('sha256', 'stale-token-value'),
            'last_heartbeat_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($admin, 'web')
            ->get(route('admin.devices'))
            ->assertOk()
            ->assertSee('Desk phone')
            ->assertSee('غير متصل');
    }

    private function serviceAccountPath(): string
    {
        $path = storage_path('framework/testing-fcm-account.json');
        file_put_contents($path, json_encode([
            'project_id' => 'almazen-test',
            'client_email' => 'fcm-test@almazen-test.iam.gserviceaccount.com',
            'private_key' => <<<'KEY'
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDEvE6qs0N95tLa
/UWm1Bv8rN6WdTX4uKFgofVtaCzvUwsbl/lDpA8pimsaeBm5j0oyVEY6btrVoka0
I/vC5W0/M3bUJw6kxj8n4Vadji/et3D2rvCe/ig/v/GuvesS4G0YQ4/4w64Q41S2
WfEh6BFXvrblE9Smk326uQiQUX1xiAZnLQNEiBOTTpczk4xYklA0dbQyuFivYb2S
rru8TFp+mtyZgMtEJ39pzng8eWZhB/ATB1a06PqYAI+KITCg3ev0Dra7CIdYJDhf
aRjA5jSiw0B/tnmmtXmncglcYFK2UikHJlFzDU9gTxTrLKBWVqetOqAH9vF4a5BD
k4OBRlkbAgMBAAECggEAGqB57TSR/+pLw4Pf93Jhz1L+VVLL2Tvz64ZAF2ywGDwf
Fr7856C+xJqNYGm84ZZ5LlKlpqb2S0/JTfxIyNoQbStj+9bFbpu+VIF1HaHu7K/b
RCftPTec7vPlnAbsWAO0AEK0q7CgUHzULFIEBtjH8B5J1rtWXq07qRf+Brpgxgqs
QPYBwHd/eWHQx9CZVz3ePVg2nl1zF3iTsnnAwWlXXKVEfolHKPY/QuK661DEKstL
5teAL7LWXQ6wv6b0m1qXBdkSgRXAA/g4aeLC7S2NGMzTEQbdHuQnZ2WUJIeUpKfI
eNpto5+zGTB43rvk/3WnCJlr8wIbLaYjcKery59A0QKBgQDklAH+aWt7/zxKPCN5
scpQzz8li8D0rERpjDH7UJB26m1mdgxRdXuX/nDvgoeU2QGbzgRKsEMRwx9LpkdU
asq/lO5/hBFZPSKXHX52bL18WmuXZR/FnXeTIO184fhDkswLCd/ioWymUcqEoovK
6g1v+qt88+DV4ct5VleYydxi3wKBgQDcVl03lJijyksnHHS3DNuUoZP0HKkxMI1G
xscBU0G5uvL0nVBobQWZOu+xiPJ35pcFEfHQ4CNIBO64XU7bR1tuZMeosWNSEyY3
C3M9fS+jfgbaSbFAXfEe7vepOI2LhjejtuKdvvHMLyS+BeD4pwdmRb0gS50sZIqz
Y9JxztGtRQKBgQCnj0Au6cSbLy1ZFeDDkutGdcZKlborIiU8YkM5gYOFSLBNzfeV
t1OUxjavvLX7DWE6f7lxruhbmvfUEt5zKZ3a9ygmXgABtOkocbyEv43gHMCGSOET
ki1Xs2mkhFnj3zaoWOLAVak5+vdEZ4umghC7p7wSawzEfTMVjY/FNyTRbQKBgD1F
4xxevWfnDKkut6Jj9/hISq26/q4IM3F4O8U5kvCEdaXizYaMst7ezeNQEyWWwW6O
g/GzMvyHKp+oixJdsKZtbjySP+2GF718+Koj0wijNmKj1Z/xv+j7SXIMoQgo5fYo
GLMHc5R1k8EoP1zGjj85q5N8k5PRzeR/IPm3KwYFAoGBAIo55gch79M7jP1JCdqI
n6rCHHw7JX+P6lyvXlUIxHrWCBGMQk5PLTKUsBoJaysG6Y8nulvIx1wQ4W+iAc29
efmRbAMw8ay306PXQU8WOfuNjquSNDK/2K79mWBFpUvjIvoT4UULxjDp5VgdhTxt
xqEfFdxrzuKaPymIuBYK3eO1
-----END PRIVATE KEY-----
KEY,
        ], JSON_THROW_ON_ERROR));

        return $path;
    }
}
