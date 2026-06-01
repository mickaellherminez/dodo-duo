<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function authenticate(User $user): void
{
    Sanctum::actingAs($user);
}

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('WorkspaceController - Index', function () {
    test('can list workspaces where user is owner', function () {
        authenticate($this->user);
        $workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->getJson('/api/v1/workspaces');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $workspace->id]);
    });

    test('can list workspaces where user is member', function () {
        authenticate($this->user);
        $workspace = Workspace::factory()->create();
        $workspace->members()->attach($this->user->id, ['role' => 'member']);

        $response = $this->getJson('/api/v1/workspaces');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $workspace->id]);
    });

    test('does not list workspaces where user has no access', function () {
        authenticate($this->user);
        Workspace::factory()->create(); // Different owner, no membership

        $response = $this->getJson('/api/v1/workspaces');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    });

    test('requires authentication', function () {
        // Create new test instance without authentication
        $response = test()->getJson('/api/v1/workspaces');
        $response->assertUnauthorized();
    });
});

describe('WorkspaceController - Store', function () {
    test('can create a workspace', function () {
        authenticate($this->user);
        $data = [
            'name' => 'Test Workspace',
            'slug' => 'test-workspace',
            'domain' => 'test.example.com',
            'settings' => ['feature_x' => true],
        ];

        $response = $this->postJson('/api/v1/workspaces', $data);

        $response->assertCreated()
            ->assertJsonFragment([
                'name' => 'Test Workspace',
                'slug' => 'test-workspace',
                'domain' => 'test.example.com',
            ]);

        $this->assertDatabaseHas('workspaces', [
            'name' => 'Test Workspace',
            'slug' => 'test-workspace',
            'owner_id' => $this->user->id,
        ]);
    });

    test('automatically adds creator as owner member', function () {
        authenticate($this->user);
        $data = [
            'name' => 'Test Workspace',
            'slug' => 'test-workspace',
        ];

        $response = $this->postJson('/api/v1/workspaces', $data);

        $response->assertCreated();

        $workspace = Workspace::where('slug', 'test-workspace')->first();

        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $this->user->id,
            'role' => 'owner',
        ]);
    });

    test('validates required fields', function () {
        authenticate($this->user);
        $response = $this->postJson('/api/v1/workspaces', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'slug']);
    });

    test('validates slug format', function () {
        authenticate($this->user);
        $response = $this->postJson('/api/v1/workspaces', [
            'name' => 'Test',
            'slug' => 'Invalid Slug!',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    });

    test('validates slug uniqueness', function () {
        authenticate($this->user);
        Workspace::factory()->create(['slug' => 'existing-slug']);

        $response = $this->postJson('/api/v1/workspaces', [
            'name' => 'Test',
            'slug' => 'existing-slug',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    });

    test('validates domain format', function () {
        authenticate($this->user);
        $response = $this->postJson('/api/v1/workspaces', [
            'name' => 'Test',
            'slug' => 'test',
            'domain' => 'invalid domain!',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['domain']);
    });

    test('validates domain uniqueness', function () {
        authenticate($this->user);
        Workspace::factory()->withDomain('existing.com')->create();

        $response = $this->postJson('/api/v1/workspaces', [
            'name' => 'Test',
            'slug' => 'test',
            'domain' => 'existing.com',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['domain']);
    });

    test('settings are optional', function () {
        authenticate($this->user);
        $response = $this->postJson('/api/v1/workspaces', [
            'name' => 'Test',
            'slug' => 'test',
        ]);

        $response->assertCreated();
    });

    test('requires authentication', function () {
        // Create new test instance without authentication
        $response = test()->postJson('/api/v1/workspaces', [
            'name' => 'Test',
            'slug' => 'test',
        ]);
        $response->assertUnauthorized();
    });
});

describe('WorkspaceController - Show', function () {
    test('can view workspace as owner', function () {
        authenticate($this->user);
        $workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->getJson("/api/v1/workspaces/{$workspace->id}");

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $workspace->id,
                'name' => $workspace->name,
            ]);
    });

    test('can view workspace as member', function () {
        authenticate($this->user);
        $workspace = Workspace::factory()->create();
        $workspace->members()->attach($this->user->id, ['role' => 'member']);

        $response = $this->getJson("/api/v1/workspaces/{$workspace->id}");

        $response->assertOk()
            ->assertJsonFragment(['id' => $workspace->id]);
    });

    test('cannot view workspace without access', function () {
        authenticate($this->user);
        $workspace = Workspace::factory()->create();

        $response = $this->getJson("/api/v1/workspaces/{$workspace->id}");

        $response->assertForbidden();
    });

    test('includes owner information', function () {
        authenticate($this->user);
        $workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->getJson("/api/v1/workspaces/{$workspace->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'owner' => ['id', 'name', 'email'],
                ],
            ]);
    });

    test('includes member count', function () {
        authenticate($this->user);
        $workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $workspace->members()->attach(User::factory()->create()->id, ['role' => 'member']);

        $response = $this->getJson("/api/v1/workspaces/{$workspace->id}");

        $response->assertOk()
            ->assertJsonPath('data.member_count', 1);
    });

    test('requires authentication', function () {
        $workspace = Workspace::factory()->create();
        // Create new test instance without authentication
        $response = test()->getJson("/api/v1/workspaces/{$workspace->id}");
        $response->assertUnauthorized();
    });
});

describe('WorkspaceController - Update', function () {
    test('owner can update workspace name', function () {
        authenticate($this->user);
        $workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->patchJson("/api/v1/workspaces/{$workspace->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Updated Name']);

        $this->assertDatabaseHas('workspaces', [
            'id' => $workspace->id,
            'name' => 'Updated Name',
        ]);
    });

    test('admin can update workspace', function () {
        authenticate($this->user);
        $workspace = Workspace::factory()->create();
        $workspace->members()->attach($this->user->id, ['role' => 'admin']);

        $response = $this->patchJson("/api/v1/workspaces/{$workspace->id}", [
            'name' => 'Updated by Admin',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Updated by Admin']);
    });

    test('member cannot update workspace', function () {
        authenticate($this->user);
        $workspace = Workspace::factory()->create();
        $workspace->members()->attach($this->user->id, ['role' => 'member']);

        $response = $this->patchJson("/api/v1/workspaces/{$workspace->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertForbidden();
    });

    test('cannot update slug', function () {
        authenticate($this->user);
        $workspace = Workspace::factory()->create([
            'owner_id' => $this->user->id,
            'slug' => 'original-slug',
        ]);

        $response = $this->patchJson("/api/v1/workspaces/{$workspace->id}", [
            'slug' => 'new-slug',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);

        $this->assertDatabaseHas('workspaces', [
            'id' => $workspace->id,
            'slug' => 'original-slug',
        ]);
    });

    test('merges settings', function () {
        authenticate($this->user);
        $workspace = Workspace::factory()->create([
            'owner_id' => $this->user->id,
            'settings' => ['existing_key' => 'value'],
        ]);

        $response = $this->patchJson("/api/v1/workspaces/{$workspace->id}", [
            'settings' => ['new_key' => 'new_value'],
        ]);

        $response->assertOk();

        $workspace->refresh();

        expect($workspace->settings)->toBe([
            'existing_key' => 'value',
            'new_key' => 'new_value',
        ]);
    });

    test('can update domain', function () {
        authenticate($this->user);
        $workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->patchJson("/api/v1/workspaces/{$workspace->id}", [
            'domain' => 'new.example.com',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['domain' => 'new.example.com']);
    });

    test('validates domain uniqueness on update', function () {
        authenticate($this->user);
        Workspace::factory()->withDomain('existing.com')->create();
        $workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->patchJson("/api/v1/workspaces/{$workspace->id}", [
            'domain' => 'existing.com',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['domain']);
    });

    test('requires authentication', function () {
        $workspace = Workspace::factory()->create();
        // Create new test instance without authentication
        $response = test()->patchJson("/api/v1/workspaces/{$workspace->id}", [
            'name' => 'Test',
        ]);
        $response->assertUnauthorized();
    });
});

describe('WorkspaceController - Destroy', function () {
    test('owner can delete workspace', function () {
        authenticate($this->user);
        $workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->deleteJson("/api/v1/workspaces/{$workspace->id}");

        $response->assertNoContent();

        $this->assertSoftDeleted('workspaces', ['id' => $workspace->id]);
    });

    test('admin cannot delete workspace', function () {
        authenticate($this->user);
        $workspace = Workspace::factory()->create();
        $workspace->members()->attach($this->user->id, ['role' => 'admin']);

        $response = $this->deleteJson("/api/v1/workspaces/{$workspace->id}");

        $response->assertForbidden();

        $this->assertDatabaseHas('workspaces', [
            'id' => $workspace->id,
            'deleted_at' => null,
        ]);
    });

    test('member cannot delete workspace', function () {
        authenticate($this->user);
        $workspace = Workspace::factory()->create();
        $workspace->members()->attach($this->user->id, ['role' => 'member']);

        $response = $this->deleteJson("/api/v1/workspaces/{$workspace->id}");

        $response->assertForbidden();
    });

    test('cascades to workspace_members on delete', function () {
        authenticate($this->user);
        $workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $member = User::factory()->create();
        $workspace->members()->attach($member->id, ['role' => 'member']);

        $this->deleteJson("/api/v1/workspaces/{$workspace->id}");

        $this->assertDatabaseMissing('workspace_members', [
            'workspace_id' => $workspace->id,
        ]);
    });

    test('requires authentication', function () {
        $workspace = Workspace::factory()->create();
        // Create new test instance without authentication
        $response = test()->deleteJson("/api/v1/workspaces/{$workspace->id}");
        $response->assertUnauthorized();
    });
});

describe('WorkspaceController - Resource Structure', function () {
    test('resource has correct structure', function () {
        authenticate($this->user);
        $workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->getJson("/api/v1/workspaces/{$workspace->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'slug',
                    'domain',
                    'status',
                    'settings',
                    'owner' => ['id', 'name', 'email'],
                    'member_count',
                    'created_at',
                    'updated_at',
                ],
            ]);
    });

    test('collection has correct structure', function () {
        authenticate($this->user);
        Workspace::factory()->create(['owner_id' => $this->user->id]);

        $response = $this->getJson('/api/v1/workspaces');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'domain',
                        'status',
                        'settings',
                        'owner',
                        'member_count',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);
    });
});
