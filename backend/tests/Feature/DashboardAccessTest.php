<?php

namespace Tests\Feature;

use App\Enums\MessageStatus;
use App\Models\ApiKey;
use App\Models\Device;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_dashboard_requires_an_active_admin_session(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $operator = User::factory()->create(['email' => 'operator@example.test', 'password' => 'operator-password']);

        $operator->forceFill(['role' => 'operator'])->save();
        $this->post('/login', ['email' => $operator->email, 'password' => 'operator-password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $disabledAdmin = User::factory()->create(['password' => 'admin-password']);
        $disabledAdmin->forceFill(['role' => 'admin', 'status' => 'disabled'])->save();
        $this->post('/login', ['email' => $disabledAdmin->email, 'password' => 'admin-password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_admin_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => 'owner@example.test', 'password' => 'wrong-password'])->assertRedirect();
        }

        $this->post('/login', ['email' => 'owner@example.test', 'password' => 'wrong-password'])->assertTooManyRequests();
    }

    public function test_admin_login_regenerates_session_and_revokes_dashboard_access_if_disabled(): void
    {
        $admin = User::factory()->create(['password' => 'admin-password']);
        $admin->forceFill(['role' => 'admin'])->save();

        $this->post('/login', ['email' => $admin->email, 'password' => 'admin-password'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
        $this->get('/admin')->assertOk()->assertSee('نظرة عامة');

        $admin->forceFill(['status' => 'disabled'])->save();
        $this->get('/admin')->assertForbidden();
    }

    public function test_admin_project_and_quota_changes_are_validated_and_audited(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertSame('active', $admin->fresh()->status);
        $owner = User::factory()->create();

        $this->actingAs($admin, 'web');
        $this->get('/admin')->assertOk();
        $this->post('/admin/projects', [
            'name' => 'Gateway East', 'owner_id' => $owner->id, 'daily_message_limit' => 40,
        ])->assertRedirect();

        $project = Project::query()->firstOrFail();
        $this->assertSame(40, $project->daily_message_limit);
        $this->assertDatabaseHas('audit_logs', ['action' => 'project.created', 'subject_id' => (string) $project->id]);

        $this->patch(route('admin.projects.update', $project), [
            'name' => 'Gateway East', 'owner_id' => $owner->id, 'daily_message_limit' => 9999, 'status' => 'paused',
        ])->assertSessionHasErrors('daily_message_limit');
        $this->assertSame('active', $project->fresh()->status);

        $this->patch(route('admin.projects.update', $project), [
            'name' => 'Gateway East', 'owner_id' => $owner->id, 'daily_message_limit' => 25, 'status' => 'paused',
        ])->assertRedirect();
        $this->assertSame('paused', $project->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'project.updated', 'subject_id' => (string) $project->id]);
    }

    public function test_admin_user_access_changes_are_audited_and_self_lockout_is_denied(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $operator = User::factory()->create();
        $this->actingAs($admin, 'web');

        $this->patch(route('admin.users.update', $admin), ['role' => 'operator', 'status' => 'active'])->assertUnprocessable();
        $this->patch(route('admin.users.update', $operator), ['role' => 'operator', 'status' => 'disabled'])->assertRedirect();
        $this->assertSame('disabled', $operator->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.updated', 'subject_id' => (string) $operator->id]);
    }

    public function test_admin_credentials_are_hashed_shown_once_and_never_cached(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $project = Project::query()->create(['owner_id' => $admin->id, 'name' => 'Private Project']);

        $keyResponse = $this->actingAs($admin, 'web')->post(route('admin.keys.store', $project), [
            'name' => 'production',
            'daily_message_limit' => 20,
            'requests_per_minute' => 10,
        ]);
        $keyResponse->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $secret = ApiKey::query()->firstOrFail();
        $this->assertSame(hash('sha256', (string) $keyResponse->viewData('credential')), $secret->key_hash);
        $this->assertStringNotContainsString((string) $keyResponse->viewData('credential'), json_encode($secret->getAttributes(), JSON_THROW_ON_ERROR));
        $this->get(route('admin.keys'))->assertOk()->assertDontSee((string) $keyResponse->viewData('credential'));
        $this->delete(route('admin.keys.revoke', [$project, $secret]))->assertRedirect();
        $this->assertNotNull($secret->fresh()->revoked_at);

        $deviceResponse = $this->post(route('admin.devices.store', $project), ['name' => 'Android gateway']);
        $deviceResponse->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $device = Device::query()->firstOrFail();
        $deviceCredential = (string) $deviceResponse->viewData('credential');
        $this->assertSame(hash('sha256', $deviceCredential), $device->token_hash);
        $this->assertStringNotContainsString($deviceCredential, json_encode($device->getAttributes(), JSON_THROW_ON_ERROR));
        $this->delete(route('admin.devices.revoke', [$project, $device]))->assertRedirect();
        $this->assertNotNull($device->fresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'api_key.created', 'subject_id' => (string) $secret->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.enrolled', 'subject_id' => $device->id]);
    }

    public function test_message_search_and_dashboard_views_never_render_recipient_or_message_body(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $project = Project::query()->create(['owner_id' => $admin->id, 'name' => 'Private Project']);
        $message = Message::query()->create([
            'project_id' => $project->id,
            'idempotency_key' => 'dashboard-test-key-0001',
            'payload_hash' => hash('sha256', 'private payload'),
            'destination' => '+967700000001',
            'body' => 'private message text',
            'status' => MessageStatus::Unknown,
            'queued_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'web')->get(route('admin.messages', ['q' => substr($message->id, 0, 8)]));
        $response->assertOk()->assertSee($message->id)->assertSee('unknown')->assertDontSee('+967700000001')->assertDontSee('private message text');
        $this->get(route('admin.index'))->assertOk()->assertDontSee('+967700000001')->assertDontSee('private message text');
    }

    public function test_cross_project_revocation_is_not_authorized_and_changes_are_csrf_protected_by_web_middleware(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $firstProject = Project::query()->create(['owner_id' => $admin->id, 'name' => 'First']);
        $secondProject = Project::query()->create(['owner_id' => User::factory()->create()->id, 'name' => 'Second']);
        $apiKey = ApiKey::query()->create([
            'project_id' => $firstProject->id, 'name' => 'primary', 'prefix' => 'azm_live_test_prefix',
            'key_hash' => hash('sha256', Str::random(48)),
        ]);

        $this->actingAs($admin, 'web')->delete(route('admin.keys.revoke', [$secondProject, $apiKey]))->assertNotFound();
        $this->assertNull($apiKey->fresh()->revoked_at);
        $this->assertContains('web', app('router')->getRoutes()->getByName('admin.keys.revoke')->gatherMiddleware());
    }

    public function test_every_dashboard_section_renders_for_an_authorized_admin(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($admin, 'web');

        foreach (['admin.index', 'admin.projects', 'admin.users', 'admin.devices', 'admin.keys', 'admin.messages', 'admin.usage', 'admin.otp', 'admin.audit', 'admin.diagnostics'] as $routeName) {
            $this->get(route($routeName))->assertOk();
        }
    }
}
