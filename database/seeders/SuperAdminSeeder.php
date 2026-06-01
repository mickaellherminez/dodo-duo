<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    /**
     * Seed a local/dev super-admin account.
     */
    public function run(): void
    {
        $email = (string) env('SUPER_ADMIN_EMAIL', 'superadmin@example.com');
        $name = (string) env('SUPER_ADMIN_NAME', 'Local Super Admin');
        $password = (string) env('SUPER_ADMIN_PASSWORD', 'password');

        $user = User::firstOrNew(['email' => $email]);

        $user->fill([
            'name' => $name,
            'password' => $password, // "hashed" cast hashes automatically
        ]);

        if ($user->email_verified_at === null) {
            $user->email_verified_at = now();
        }

        $user->forceFill([
            'is_super_admin' => true,
        ]);

        $user->save();

        $this->command?->info("Super-admin ready: {$email}");
        $this->command?->warn('For local/dev only. Use secure credentials and secrets management in production.');
    }
}
