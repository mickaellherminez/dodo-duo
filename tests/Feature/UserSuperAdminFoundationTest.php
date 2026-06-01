<?php

use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

describe('Super-admin foundation', function () {
    test('users table has is_super_admin column and index', function () {
        expect(Schema::hasColumn('users', 'is_super_admin'))->toBeTrue();

        $indexNames = collect(Schema::getIndexes('users'))->pluck('name');

        expect($indexNames->contains(fn ($name) => str_contains((string) $name, 'is_super_admin')))
            ->toBeTrue();
    });

    test('user model casts is_super_admin and defaults to false', function () {
        $user = User::factory()->create();

        expect($user->refresh()->is_super_admin)->toBeFalse()
            ->and($user->is_super_admin)->toBeBool();
    });

    test('super admin seeder is idempotent and promotes configured account', function () {
        User::factory()->create([
            'email' => 'superadmin@example.com',
            'is_super_admin' => false,
        ]);

        $this->seed(SuperAdminSeeder::class);
        $this->seed(SuperAdminSeeder::class);

        $superAdmin = User::where('email', 'superadmin@example.com')->first();

        expect($superAdmin)->not->toBeNull()
            ->and($superAdmin?->is_super_admin)->toBeTrue()
            ->and(User::where('email', 'superadmin@example.com')->count())->toBe(1);
    });
});
