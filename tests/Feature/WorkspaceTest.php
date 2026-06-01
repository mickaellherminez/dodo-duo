<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Workspace Database Schema', function () {
    it('creates workspaces table with correct structure', function () {
        // Test that the table exists
        expect(\Illuminate\Support\Facades\Schema::hasTable('workspaces'))->toBeTrue();

        // Test required columns exist
        $columns = ['id', 'name', 'slug', 'domain', 'status', 'owner_id', 'settings', 'trial_ends_at', 'created_at', 'updated_at', 'deleted_at'];

        foreach ($columns as $column) {
            expect(\Illuminate\Support\Facades\Schema::hasColumn('workspaces', $column))->toBeTrue();
        }
    });

    it('has unique constraints on slug and domain', function () {
        $user = User::factory()->create();

        Workspace::create([
            'name' => 'Test Workspace',
            'slug' => 'test-workspace',
            'domain' => 'test.example.com',
            'owner_id' => $user->id,
            'status' => 'active',
        ]);

        // Duplicate slug should fail
        expect(fn () => Workspace::create([
            'name' => 'Another Workspace',
            'slug' => 'test-workspace',
            'owner_id' => $user->id,
        ]))->toThrow(\Illuminate\Database\QueryException::class);
    });

    it('cascades delete when owner is deleted', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        expect(Workspace::count())->toBe(1);

        $user->delete();

        expect(Workspace::count())->toBe(0);
    });
});

describe('Workspace Model', function () {
    it('has fillable attributes', function () {
        $workspace = new Workspace;

        $fillable = ['name', 'slug', 'domain', 'status', 'owner_id', 'settings', 'trial_ends_at'];

        expect($workspace->getFillable())->toEqual($fillable);
    });

    it('casts settings to array', function () {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Test Workspace',
            'slug' => 'test-workspace',
            'owner_id' => $user->id,
            'settings' => ['theme' => 'dark'],
        ]);

        expect($workspace->settings)->toBeArray();
        expect($workspace->settings['theme'])->toBe('dark');
    });

    it('casts trial_ends_at to datetime', function () {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Test Workspace',
            'slug' => 'test-workspace',
            'owner_id' => $user->id,
            'trial_ends_at' => '2026-03-01 00:00:00',
        ]);

        expect($workspace->trial_ends_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    });

    it('uses soft deletes', function () {
        $workspace = Workspace::factory()->create();
        $id = $workspace->id;

        $workspace->delete();

        expect(Workspace::count())->toBe(0);
        expect(Workspace::withTrashed()->find($id))->not->toBeNull();
    });

    it('has owner relationship', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        expect($workspace->owner)->toBeInstanceOf(User::class);
        expect($workspace->owner->id)->toBe($user->id);
    });

    it('defaults status to active', function () {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Test Workspace',
            'slug' => 'test-workspace',
            'owner_id' => $user->id,
        ]);

        expect($workspace->status)->toBe('active');
    });
});

describe('Workspace Factory', function () {
    it('creates workspace with factory', function () {
        $workspace = Workspace::factory()->create();

        expect($workspace)->toBeInstanceOf(Workspace::class);
        expect($workspace->name)->not->toBeEmpty();
        expect($workspace->slug)->not->toBeEmpty();
        expect($workspace->owner_id)->not->toBeNull();
    });

    it('creates suspended workspace', function () {
        $workspace = Workspace::factory()->suspended()->create();

        expect($workspace->status)->toBe('suspended');
    });

    it('creates archived workspace', function () {
        $workspace = Workspace::factory()->archived()->create();

        expect($workspace->status)->toBe('archived');
    });

    it('creates workspace with domain', function () {
        $workspace = Workspace::factory()->withDomain()->create();

        expect($workspace->domain)->not->toBeNull();
    });

    it('generates unique slugs', function () {
        $workspace1 = Workspace::factory()->create();
        $workspace2 = Workspace::factory()->create();

        expect($workspace1->slug)->not->toBe($workspace2->slug);
    });
});

describe('Workspace Seeder', function () {
    it('seeds default workspaces', function () {
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);

        expect(Workspace::count())->toBe(3);
        expect(User::count())->toBe(3);
    });

    it('creates workspaces with correct data', function () {
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);

        $acme = Workspace::where('slug', 'acme-corp')->first();

        expect($acme)->not->toBeNull();
        expect($acme->name)->toBe('Acme Corporation');
        expect($acme->domain)->toBe('acme.saasforge.local');
        expect($acme->status)->toBe('active');
    });
});
