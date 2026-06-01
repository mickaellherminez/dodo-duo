<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function signIn(User $user): void
{
    Sanctum::actingAs($user);
}

function wsHeader(Workspace $workspace): array
{
    return ['X-Workspace-ID' => $workspace->id];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create();
    $this->workspace->members()->attach($this->user->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
});

// ---------------------------------------------------------------------------
// Index
// ---------------------------------------------------------------------------

describe('ProjectController - Index', function () {
    test('returns projects belonging to current workspace', function () {
        signIn($this->user);
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $project->id);
    });

    test('does not return projects from other workspaces', function () {
        signIn($this->user);
        $other = Workspace::factory()->create();
        Project::factory()->forWorkspace($other)->create();

        $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    test('returns pagination meta', function () {
        signIn($this->user);
        Project::factory()->count(3)->forWorkspace($this->workspace)->create();

        $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'per_page', 'current_page', 'last_page']]);
    });

    test('supports custom per_page query parameter', function () {
        signIn($this->user);
        Project::factory()->count(3)->forWorkspace($this->workspace)->create();

        $response = $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        expect(collect((array) data_get($response->json(), 'meta.per_page'))->contains(2))->toBeTrue();
    });

    test('supports page query parameter', function () {
        signIn($this->user);
        Project::factory()->count(3)->forWorkspace($this->workspace)->create();

        $response = $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        expect(collect((array) data_get($response->json(), 'meta.current_page'))->contains(2))->toBeTrue()
            ->and(collect((array) data_get($response->json(), 'meta.per_page'))->contains(2))->toBeTrue();
    });

    test('caps per_page at 100', function () {
        signIn($this->user);
        Project::factory()->count(3)->forWorkspace($this->workspace)->create();

        $response = $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects?per_page=101')
            ->assertOk();

        expect(collect((array) data_get($response->json(), 'meta.per_page'))->contains(100))->toBeTrue();
    });

    test('validates pagination query parameters', function () {
        signIn($this->user);

        $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects?per_page=0&page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page', 'page']);
    });

    test('filters projects by single status', function () {
        signIn($this->user);
        Project::factory()->forWorkspace($this->workspace)->create(['status' => 'active']);
        Project::factory()->forWorkspace($this->workspace)->create(['status' => 'archived']);

        $response = $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        expect($response->json('data.0.status'))->toBe('active');
    });

    test('filters projects by multiple statuses', function () {
        signIn($this->user);
        Project::factory()->forWorkspace($this->workspace)->create(['status' => 'active']);
        Project::factory()->forWorkspace($this->workspace)->create(['status' => 'archived']);
        Project::factory()->forWorkspace($this->workspace)->create(['status' => 'completed']);

        $response = $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects?status=archived,completed')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        expect(collect($response->json('data'))->pluck('status')->sort()->values()->all())
            ->toBe(['archived', 'completed']);
    });

    test('filters projects by creator using me shortcut', function () {
        $otherUser = User::factory()->create();
        $this->workspace->members()->attach($otherUser->id, ['role' => 'member', 'joined_at' => now()]);

        signIn($this->user);
        $ownProject = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Mine']);

        signIn($otherUser);
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Other']);

        signIn($this->user);

        $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects?created_by=me')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownProject->id);
    });

    test('filters projects by creator using member user id', function () {
        $otherUser = User::factory()->create();
        $this->workspace->members()->attach($otherUser->id, ['role' => 'member', 'joined_at' => now()]);

        signIn($this->user);
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Mine']);

        signIn($otherUser);
        $otherProject = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Other']);

        signIn($this->user);

        $this->withHeaders(wsHeader($this->workspace))
            ->getJson("/api/v1/projects?created_by={$otherUser->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $otherProject->id);
    });

    test('searches by name or description (case-insensitive for ascii)', function () {
        signIn($this->user);
        Project::factory()->forWorkspace($this->workspace)->create([
            'name' => 'Alpha Project',
            'description' => 'No match here',
        ]);
        Project::factory()->forWorkspace($this->workspace)->create([
            'name' => 'Something else',
            'description' => 'Contains beta keyword',
        ]);
        Project::factory()->forWorkspace($this->workspace)->create([
            'name' => 'Gamma',
            'description' => 'Nope',
        ]);

        $response = $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects?search=BETA')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        expect($response->json('data.0.name'))->toBe('Something else');
    });

    test('sorts projects by name ascending', function () {
        signIn($this->user);
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Zulu']);
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Alpha']);
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Mike']);

        $response = $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects?sort=name')
            ->assertOk();

        expect(collect($response->json('data'))->pluck('name')->take(3)->values()->all())
            ->toBe(['Alpha', 'Mike', 'Zulu']);
    });

    test('supports multiple sort fields', function () {
        signIn($this->user);
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Alpha', 'status' => 'active']);
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Zulu', 'status' => 'active']);
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Beta', 'status' => 'archived']);

        $response = $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects?sort=status,-name')
            ->assertOk();

        expect(collect($response->json('data'))->pluck('name')->take(3)->values()->all())
            ->toBe(['Zulu', 'Alpha', 'Beta']);
    });

    test('supports combined status search sort and pagination filters', function () {
        signIn($this->user);
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Bravo Alpha', 'status' => 'active']);
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Alpha Project', 'status' => 'active']);
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Alpha Archived', 'status' => 'archived']);

        $response = $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects?status=active&search=alpha&sort=name&per_page=20')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        expect(collect($response->json('data'))->pluck('name')->values()->all())
            ->toBe(['Alpha Project', 'Bravo Alpha']);
    });

    test('trims whitespace in comma separated status filter', function () {
        signIn($this->user);
        Project::factory()->forWorkspace($this->workspace)->create(['status' => 'archived']);
        Project::factory()->forWorkspace($this->workspace)->create(['status' => 'completed']);
        Project::factory()->forWorkspace($this->workspace)->create(['status' => 'active']);

        $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects?status=%20archived%20,%20completed%20')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    test('validates invalid status filter values', function () {
        signIn($this->user);

        $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects?status=active,invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    });

    test('validates invalid sort fields', function () {
        signIn($this->user);

        $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects?sort=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort']);
    });

    test('validates malformed sort tokens with multiple direction prefixes', function () {
        signIn($this->user);

        $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects?sort=--created_at')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort']);
    });

    test('uses latest created_at descending as default sort', function () {
        signIn($this->user);
        $older = Project::factory()->forWorkspace($this->workspace)->create([
            'name' => 'Older',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $newer = Project::factory()->forWorkspace($this->workspace)->create([
            'name' => 'Newer',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    });

    test('validates created_by filter format', function () {
        signIn($this->user);

        $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects?created_by=not-a-user')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['created_by']);
    });

    test('validates created_by filter requires active workspace member', function () {
        signIn($this->user);
        $outsider = User::factory()->create();

        $this->withHeaders(wsHeader($this->workspace))
            ->getJson("/api/v1/projects?created_by={$outsider->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['created_by']);
    });

    test('preserves workspace isolation when filters are active', function () {
        signIn($this->user);
        $otherWorkspace = Workspace::factory()->create();

        $inScope = Project::factory()->forWorkspace($this->workspace)->create([
            'name' => 'Alpha Visible',
            'status' => 'active',
        ]);

        Project::factory()->forWorkspace($otherWorkspace)->create([
            'name' => 'Alpha Hidden',
            'status' => 'active',
        ]);

        $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects?status=active&search=alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inScope->id);
    });

    test('returns 403 for non-member', function () {
        $outsider = User::factory()->create();
        signIn($outsider);

        $this->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects')
            ->assertForbidden();
    });

    test('requires authentication', function () {
        test()->withHeaders(wsHeader($this->workspace))
            ->getJson('/api/v1/projects')
            ->assertUnauthorized();
    });

    test('returns 404 when workspace context is missing', function () {
        signIn($this->user);

        $this->getJson('/api/v1/projects')
            ->assertNotFound();
    });
});

// ---------------------------------------------------------------------------
// Store
// ---------------------------------------------------------------------------

describe('ProjectController - Store', function () {
    test('creates project and returns 201', function () {
        signIn($this->user);

        $this->withHeaders(wsHeader($this->workspace))
            ->postJson('/api/v1/projects', ['name' => 'New Project', 'status' => 'active'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'New Project')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.workspace_id', $this->workspace->id);

        $this->assertDatabaseHas('projects', [
            'name' => 'New Project',
            'workspace_id' => $this->workspace->id,
        ]);
    });

    test('auto-sets workspace_id and created_by', function () {
        signIn($this->user);

        $this->withHeaders(wsHeader($this->workspace))
            ->postJson('/api/v1/projects', ['name' => 'Audit Project', 'status' => 'active'])
            ->assertCreated()
            ->assertJsonPath('data.workspace_id', $this->workspace->id)
            ->assertJsonPath('data.created_by', $this->user->id);
    });

    test('validates required fields', function () {
        signIn($this->user);

        $this->withHeaders(wsHeader($this->workspace))
            ->postJson('/api/v1/projects', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'status']);
    });

    test('validates invalid status value', function () {
        signIn($this->user);

        $this->withHeaders(wsHeader($this->workspace))
            ->postJson('/api/v1/projects', ['name' => 'Test', 'status' => 'invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    });

    test('rejects duplicate name in same workspace', function () {
        signIn($this->user);
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Duplicate']);

        $this->withHeaders(wsHeader($this->workspace))
            ->postJson('/api/v1/projects', ['name' => 'Duplicate', 'status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    test('allows same name in different workspace', function () {
        signIn($this->user);
        $other = Workspace::factory()->create();
        Project::factory()->forWorkspace($other)->create(['name' => 'Shared Name']);

        $this->withHeaders(wsHeader($this->workspace))
            ->postJson('/api/v1/projects', ['name' => 'Shared Name', 'status' => 'active'])
            ->assertCreated();
    });

    test('returns 403 when guest lacks create permission', function () {
        $guest = User::factory()->create();
        $this->workspace->members()->attach($guest->id, ['role' => 'guest', 'joined_at' => now()]);
        signIn($guest);

        $this->withHeaders(wsHeader($this->workspace))
            ->postJson('/api/v1/projects', ['name' => 'Forbidden', 'status' => 'active'])
            ->assertForbidden();
    });

    test('requires authentication', function () {
        test()->withHeaders(wsHeader($this->workspace))
            ->postJson('/api/v1/projects', ['name' => 'Test', 'status' => 'active'])
            ->assertUnauthorized();
    });

    test('returns 404 when workspace context is missing', function () {
        signIn($this->user);

        $this->postJson('/api/v1/projects', ['name' => 'No Context', 'status' => 'active'])
            ->assertNotFound();
    });
});

// ---------------------------------------------------------------------------
// Show
// ---------------------------------------------------------------------------

describe('ProjectController - Show', function () {
    test('returns project with 200', function () {
        signIn($this->user);
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        $this->withHeaders(wsHeader($this->workspace))
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $project->id);
    });

    test('returns 404 for project in other workspace', function () {
        signIn($this->user);
        $other = Workspace::factory()->create();
        $project = Project::factory()->forWorkspace($other)->create();

        $this->withHeaders(wsHeader($this->workspace))
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertNotFound();
    });

    test('returns 404 for soft-deleted project', function () {
        signIn($this->user);
        $project = Project::factory()->forWorkspace($this->workspace)->create();
        $project->delete();

        $this->withHeaders(wsHeader($this->workspace))
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertNotFound();
    });

    test('requires authentication', function () {
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        test()->withHeaders(wsHeader($this->workspace))
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertUnauthorized();
    });

    test('returns 404 when workspace context is missing', function () {
        signIn($this->user);
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        $this->getJson("/api/v1/projects/{$project->id}")
            ->assertNotFound();
    });
});

// ---------------------------------------------------------------------------
// Update
// ---------------------------------------------------------------------------

describe('ProjectController - Update', function () {
    test('admin can update any project', function () {
        signIn($this->user);
        $project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Original']);

        $this->withHeaders(wsHeader($this->workspace))
            ->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Updated'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated');

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Updated']);
    });

    test('auto-sets updated_by', function () {
        signIn($this->user);
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        $this->withHeaders(wsHeader($this->workspace))
            ->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Changed']);

        expect($project->refresh()->updated_by)->toBe($this->user->id);
    });

    test('member cannot update project they do not own', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        // Create project while authenticated as owner so observer sets created_by = owner
        signIn($owner);
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        // Switch to member — member does not own this project
        signIn($member);

        $this->withHeaders(wsHeader($this->workspace))
            ->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Hacked'])
            ->assertForbidden();
    });

    test('rejects duplicate name on update', function () {
        signIn($this->user);
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Taken']);
        $project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Mine']);

        $this->withHeaders(wsHeader($this->workspace))
            ->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Taken'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    test('allows saving with the same name (self-ignore on unique)', function () {
        signIn($this->user);
        $project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'My Project']);

        $this->withHeaders(wsHeader($this->workspace))
            ->patchJson("/api/v1/projects/{$project->id}", ['name' => 'My Project', 'status' => 'archived'])
            ->assertOk();
    });

    test('returns 404 for project in other workspace', function () {
        signIn($this->user);
        $other = Workspace::factory()->create();
        $project = Project::factory()->forWorkspace($other)->create();

        $this->withHeaders(wsHeader($this->workspace))
            ->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Updated'])
            ->assertNotFound();
    });

    test('requires authentication', function () {
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        test()->withHeaders(wsHeader($this->workspace))
            ->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Test'])
            ->assertUnauthorized();
    });
});

// ---------------------------------------------------------------------------
// Destroy
// ---------------------------------------------------------------------------

describe('ProjectController - Destroy', function () {
    test('admin can soft-delete project', function () {
        signIn($this->user);
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        $this->withHeaders(wsHeader($this->workspace))
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('projects', ['id' => $project->id]);
    });

    test('member cannot delete project they do not own', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        // Create project while authenticated as owner so observer sets created_by = owner
        signIn($owner);
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        // Switch to member — member does not own this project
        signIn($member);

        $this->withHeaders(wsHeader($this->workspace))
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertForbidden();
    });

    test('returns 404 for project in other workspace', function () {
        signIn($this->user);
        $other = Workspace::factory()->create();
        $project = Project::factory()->forWorkspace($other)->create();

        $this->withHeaders(wsHeader($this->workspace))
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertNotFound();
    });

    test('requires authentication', function () {
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        test()->withHeaders(wsHeader($this->workspace))
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertUnauthorized();
    });
});
