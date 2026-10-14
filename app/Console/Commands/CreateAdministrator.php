<?php

namespace App\Console\Commands;

use App\Enums\SystemRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('app:create-administrator {--name=} {--email=}')]
#[Description('Securely create the first active accounting administrator')]
class CreateAdministrator extends Command
{
    public function handle(): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Administrator name'));
        $email = (string) ($this->option('email') ?: $this->ask('Administrator email'));
        $password = (string) $this->secret('Password');
        $passwordConfirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => true,
            'is_administrator' => true,
            'password_changed_at' => now(),
        ]);

        if (Role::query()->where('name', SystemRole::Administrator->value)->exists()) {
            $user->assignRole(SystemRole::Administrator->value);
        } else {
            $this->warn('Administrator role is not seeded yet. Run php artisan db:seed after migrations.');
        }

        $this->info('Administrator created successfully.');

        return self::SUCCESS;
    }
}
