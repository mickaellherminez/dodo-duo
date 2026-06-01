<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class TestUserSeeder extends Seeder
{
    /**
     * Seed a test user for API testing.
     *
     * Creates a user with known credentials for testing API endpoints.
     * Use this in development/staging environments only.
     */
    public function run(): void
    {
        // Check if test user already exists
        $testUser = User::where('email', 'test@example.com')->first();

        if ($testUser) {
            $this->command->info('✓ Test user already exists (test@example.com)');

            return;
        }

        // Create test user
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'email_verified_at' => now(),
        ]);

        $this->command->info('✓ Test user created successfully!');
        $this->command->newLine();
        $this->command->info('  Email: test@example.com');
        $this->command->info('  Password: password123');
        $this->command->newLine();
        $this->command->info('Generate API token with:');
        $this->command->info('  php artisan tinker');
        $this->command->info('  >>> User::where(\'email\', \'test@example.com\')->first()->createToken(\'test\')->plainTextToken');
    }
}
