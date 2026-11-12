<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class AdminKeyManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_can_reveal_a_stored_key_and_the_list_keeps_it_hidden(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $project = Project::query()->create(['owner_id' => $admin->id, 'name' => 'Private Project']);

        $created = $this->actingAs($admin, 'web')->post(route('admin.keys.store', $project), [
            'name' => 'production',
            'daily_message_limit' => 20,
            'requests_per_minute' => 10,
        ]);

        $created->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $secret = (string) $created->viewData('credential');
        $key = ApiKey::query()->firstOrFail();
        $this->assertSame($secret, $key->plainSecret());
        $this->assertSame(hash('sha256', $secret), $key->key_hash);
        $this->assertStringNotContainsString($secret, json_encode($key->getAttributes(), JSON_THROW_ON_ERROR));

        $this->get(route('admin.keys'))
            ->assertOk()
            ->assertSee('كشف المفتاح')
            ->assertSee('تنزيل دليل التعامل مع الخدمات')
            ->assertDontSee($secret);

        $revealed = $this->post(route('admin.keys.reveal', [$project, $key]));

        $revealed->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee($secret, false);
        $audit = AuditLog::query()->where('action', 'api_key.revealed')->firstOrFail();
        $this->assertSame((string) $key->id, $audit->subject_id);
        $this->assertStringNotContainsString($secret, json_encode($audit->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_api_created_key_can_be_revealed_by_an_admin(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $project = Project::query()->create(['owner_id' => $owner->id, 'name' => 'API project']);
        $token = $owner->createToken('tests')->plainTextToken;

        $created = $this->withToken($token)->postJson("/api/v1/projects/{$project->id}/api-keys", ['name' => 'integration']);

        $created->assertCreated();
        $secret = $created->json('secret');
        $key = ApiKey::query()->firstOrFail();
        $this->assertSame($secret, $key->plainSecret());
        $this->assertArrayNotHasKey('secret_encrypted', $created->json('data'));

        $this->actingAs($admin, 'web')
            ->post(route('admin.keys.reveal', [$project, $key]))
            ->assertOk()
            ->assertSee($secret, false);
    }

    public function test_legacy_key_without_encrypted_secret_is_not_revealed(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $project = Project::query()->create(['owner_id' => $admin->id, 'name' => 'Legacy']);
        $key = ApiKey::query()->create([
            'project_id' => $project->id,
            'name' => 'old',
            'prefix' => 'azm_live_legacy_prefix',
            'key_hash' => hash('sha256', 'azm_live_'.Str::random(48)),
        ]);

        $this->actingAs($admin, 'web')
            ->post(route('admin.keys.reveal', [$project, $key]))
            ->assertRedirect()
            ->assertSessionHasErrors('secret');

        $this->assertSame(0, AuditLog::query()->where('action', 'api_key.revealed')->count());
        $this->get(route('admin.keys'))->assertOk()->assertSee('لا يمكن استرجاع هذا المفتاح');
    }

    public function test_reveal_for_another_projects_key_returns_not_found(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $first = Project::query()->create(['owner_id' => $admin->id, 'name' => 'First']);
        $second = Project::query()->create(['owner_id' => User::factory()->create()->id, 'name' => 'Second']);
        $secret = 'azm_live_'.Str::random(48);
        $key = $first->apiKeys()->create([
            'name' => 'primary',
            ...ApiKey::protect($secret),
        ]);

        $this->actingAs($admin, 'web')
            ->post(route('admin.keys.reveal', [$second, $key]))
            ->assertNotFound();

        $this->assertSame(0, AuditLog::query()->where('action', 'api_key.revealed')->count());
    }

    public function test_guest_and_operator_cannot_reveal_a_key(): void
    {
        $project = Project::query()->create(['owner_id' => User::factory()->create()->id, 'name' => 'Private']);
        $key = $project->apiKeys()->create([
            'name' => 'primary',
            ...ApiKey::protect('azm_live_'.Str::random(48)),
        ]);

        $this->post(route('admin.keys.reveal', [$project, $key]))->assertRedirect('/login');

        $operator = User::factory()->create();
        $this->actingAs($operator, 'web')
            ->post(route('admin.keys.reveal', [$project, $key]))
            ->assertForbidden();
    }

    public function test_admin_can_rename_and_filter_keys_without_replacing_the_secret(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $project = Project::query()->create(['owner_id' => $admin->id, 'name' => 'Filtered']);
        $alphaSecret = 'azm_live_'.Str::random(48);
        $alpha = $project->apiKeys()->create([
            'name' => 'alpha gateway',
            ...ApiKey::protect($alphaSecret),
            'daily_message_limit' => 20,
            'requests_per_minute' => 10,
        ]);
        $project->apiKeys()->create([
            'name' => 'beta gateway',
            ...ApiKey::protect('azm_live_'.Str::random(48)),
            'daily_message_limit' => 20,
            'requests_per_minute' => 10,
        ]);

        $this->actingAs($admin, 'web')->patch(route('admin.keys.update', [$project, $alpha]), [
            'name' => 'alpha renamed',
            'daily_message_limit' => 30,
            'requests_per_minute' => 12,
            'secret_encrypted' => 'plaintext-should-not-stick',
        ])->assertRedirect();

        $alpha->refresh();
        $this->assertSame('alpha renamed', $alpha->name);
        $this->assertSame(30, $alpha->daily_message_limit);
        $this->assertSame($alphaSecret, $alpha->plainSecret());

        $this->get(route('admin.keys', ['q' => 'alpha']))
            ->assertOk()
            ->assertSee('alpha renamed')
            ->assertDontSee('beta gateway');

        $this->get(route('admin.keys', ['status' => 'active']))->assertOk()->assertSee('beta gateway');
        $beta = ApiKey::query()->where('name', 'beta gateway')->firstOrFail();
        $this->delete(route('admin.keys.revoke', [$project, $beta]))->assertRedirect();
        $this->get(route('admin.keys', ['status' => 'revoked']))
            ->assertOk()
            ->assertSee('beta gateway')
            ->assertDontSee('alpha renamed');
    }

    public function test_customer_guide_downloads_an_xlsx_workbook_without_a_real_key(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $project = Project::query()->create(['owner_id' => $admin->id, 'name' => 'Guide']);
        $secret = 'azm_live_'.Str::random(48);
        $project->apiKeys()->create([
            'name' => 'customer',
            ...ApiKey::protect($secret),
        ]);

        $response = $this->actingAs($admin, 'web')->get(route('admin.keys.guide'));

        $response->assertOk()->assertDownload('almazensms-customer-guide.xlsx');
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $response->headers->get('Content-Type'),
        );
        $xml = $this->workbookXml($response->getContent());
        foreach (['ابدأ هنا', 'المصادقة', 'إرسال رسالة', 'متابعة الحالة', 'طلب OTP', 'التحقق والإلغاء', 'الحصص والأخطاء', 'أمثلة جاهزة'] as $sheet) {
            $this->assertStringContainsString($sheet, $xml);
        }
        $this->assertStringContainsString('YOUR_API_KEY', $xml);
        $this->assertStringContainsString('message_recalled', $xml);
        $this->assertStringContainsString('X-API-Key', $xml);
        $this->assertStringNotContainsString($secret, $xml);
    }

    public function test_guest_and_operator_cannot_download_the_customer_guide(): void
    {
        $this->get(route('admin.keys.guide'))->assertRedirect('/login');

        $operator = User::factory()->create();
        $this->actingAs($operator, 'web')->get(route('admin.keys.guide'))->assertForbidden();
    }

    private function workbookXml(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->assertNotFalse($path);
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $workbook = $zip->getFromName('xl/workbook.xml');
        $this->assertIsString($workbook);
        $sheets = $workbook;
        for ($index = 1; $index <= 8; $index++) {
            $sheet = $zip->getFromName('xl/worksheets/sheet'.$index.'.xml');
            $this->assertIsString($sheet);
            $sheets .= $sheet;
        }
        $zip->close();
        unlink($path);

        return $sheets;
    }
}
