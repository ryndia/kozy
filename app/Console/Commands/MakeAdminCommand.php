<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class MakeAdminCommand extends Command
{
    protected $signature = 'make:admin {name} {--email= : Admin email} {--password= : Admin password}';

    protected $description = 'Create a system admin account with full back office access';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Enter admin email');
        $password = $this->option('password') ?: $this->secret('Enter admin password');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('The admin email is invalid.');

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error('A user with this email already exists.');

            return self::FAILURE;
        }

        User::create([
            'name' => $this->argument('name'),
            'email' => $email,
            'role' => UserRole::ADMIN->value,
            'password' => Hash::make($password),
            'otp_verified_at' => now(),
        ]);

        $this->info('Admin user created successfully.');

        return self::SUCCESS;
    }
}
