<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateOperator extends Command
{
    protected $signature = 'almazen:user:create {--name=} {--email=} {--role=admin}';

    protected $description = 'Create a privileged ALMAZEN SMS API user interactively.';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $email = strtolower($this->option('email') ?: $this->ask('Email'));
        $role = $this->option('role');
        if (! in_array($role, ['admin', 'operator'], true)) {
            $this->error('Role must be admin or operator.');

            return self::INVALID;
        }
        if (User::query()->where('email', $email)->exists()) {
            $this->error('That email already exists.');

            return self::FAILURE;
        }
        $password = $this->secret('Password (minimum 14 characters)');
        if (strlen($password) < 14) {
            $this->error('Password must contain at least 14 characters.');

            return self::INVALID;
        }

        $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['role' => $role, 'status' => 'active'])->save();
        $this->info("Created user {$user->id} ({$role}). No password was printed or stored in command output.");

        return self::SUCCESS;
    }
}
