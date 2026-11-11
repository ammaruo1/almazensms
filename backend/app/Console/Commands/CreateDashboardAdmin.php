<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('dashboard:admin {email?} {name?}')]
#[Description('Create an active ALMAZEN SMS dashboard administrator.')]
class CreateDashboardAdmin extends Command
{
    public function handle(): int
    {
        $email = strtolower(trim((string) ($this->argument('email') ?? $this->ask('Administrator email'))));
        $name = trim((string) ($this->argument('name') ?? $this->ask('Administrator name')));

        $validator = Validator::make(compact('email', 'name'), [
            'email' => ['required', 'email', 'max:254', 'unique:users,email'],
            'name' => ['required', 'string', 'max:120'],
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $password = (string) $this->secret('Administrator password');
        $passwordValidator = Validator::make(['password' => $password], [
            'password' => ['required', Password::min(14)->mixedCase()->numbers()->symbols()],
        ]);
        if ($passwordValidator->fails()) {
            $this->error('Password must be at least 14 characters and include upper and lower case letters, a number, and a symbol.');

            return self::FAILURE;
        }

        if ($password !== $this->secret('Confirm password')) {
            $this->error('Passwords did not match.');

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);
        $user->forceFill(['role' => 'admin', 'status' => 'active'])->save();

        $this->info('Active dashboard administrator created.');

        return self::SUCCESS;
    }
}
