<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateDashboardAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_is_created_with_a_hidden_strong_password_prompt(): void
    {
        $this->artisan('dashboard:admin owner@example.test "ALMAZEN Owner"')
            ->expectsQuestion('Administrator password', 'StrongPass1!Strong')
            ->expectsQuestion('Confirm password', 'StrongPass1!Strong')
            ->expectsOutput('Active dashboard administrator created.')
            ->assertExitCode(0);

        $admin = User::query()->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertSame('active', $admin->status);
        $this->assertTrue(Hash::check('StrongPass1!Strong', $admin->password));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_weak_admin_password_is_rejected_without_creating_an_account(): void
    {
        $this->artisan('dashboard:admin owner@example.test "ALMAZEN Owner"')
            ->expectsQuestion('Administrator password', 'weak')
            ->expectsOutputToContain('Password must be at least 14 characters')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }
}
