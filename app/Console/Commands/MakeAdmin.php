<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class MakeAdmin extends Command
{
    protected $signature = 'app:make-admin {email? : Email of the admin account}';

    protected $description = 'Create the platform admin account, or promote an existing user to admin';

    public function handle(): int
    {
        $email = $this->argument('email') ?? text('Admin email', required: true);

        $user = User::firstWhere('email', $email);

        if (! $user) {
            $user = new User([
                'name' => text('Admin name', default: 'Admin', required: true),
                'email' => $email,
                'password' => Hash::make(password('Password (min 8 characters)', required: true, validate: fn ($v) => strlen($v) < 8 ? 'Too short.' : null)),
            ]);
            $user->email_verified_at = now();
        }

        $user->is_admin = true;
        $user->save();

        $this->info("{$user->email} is now an admin.");

        return self::SUCCESS;
    }
}
