<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// Reuse helpers from ProjectControllerTest scope
function diSignIn(User $user): void
{
    Sanctum::actingAs($user);
}

function diWsHeader(Workspace $workspace): array
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
// UniqueInWorkspace rule — via Store and Update endpoints
// ---------------------------------------------------------------------------

describe('UniqueInWorkspace rule — Store', function () {
    test('rejects active duplicate name in same workspace', function () {
        diSignIn($this->user);
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Alpha']);

        $this->withHeaders(diWsHeader($this->workspace))
            ->postJson('/api/v1/projects', ['name' => 'Alpha', 'status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    test('allows same name in different workspace', function () {
        diSignIn($this->user);
        $other = Workspace::factory()->create();
        Project::factory()->forWorkspace($other)->create(['name' => 'Shared']);

        $this->withHeaders(diWsHeader($this->workspace))
            ->postJson('/api/v1/projects', ['name' => 'Shared', 'status' => 'active'])
            ->assertCreated();
    });

    test('allows creating project with name of soft-deleted project in same workspace', function () {
        diSignIn($this->user);
        $project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Reusable']);
        $project->delete();

        $this->withHeaders(diWsHeader($this->workspace))
            ->postJson('/api/v1/projects', ['name' => 'Reusable', 'status' => 'active'])
            ->assertCreated();
    });
});

describe('UniqueInWorkspace rule — Update', function () {
    test('allows update with unchanged name (self-ignore)', function () {
        diSignIn($this->user);
        $project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'My Project']);

        $this->withHeaders(diWsHeader($this->workspace))
            ->patchJson("/api/v1/projects/{$project->id}", ['name' => 'My Project', 'status' => 'archived'])
            ->assertOk();
    });

    test('rejects update to name of existing active project in same workspace', function () {
        diSignIn($this->user);
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Taken']);
        $project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Mine']);

        $this->withHeaders(diWsHeader($this->workspace))
            ->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Taken'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    test('allows update to name of soft-deleted project in same workspace', function () {
        diSignIn($this->user);
        $deleted = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Gone']);
        $deleted->delete();
        $project = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Mine']);

        $this->withHeaders(diWsHeader($this->workspace))
            ->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Gone'])
            ->assertOk();
    });
});

// ---------------------------------------------------------------------------
// Workspace cascade soft-delete
// ---------------------------------------------------------------------------

describe('Workspace cascade soft-delete', function () {
    test('soft-deleting workspace soft-deletes its projects', function () {
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        $this->workspace->delete();

        $this->assertSoftDeleted('projects', ['id' => $project->id]);
        $this->assertSoftDeleted('workspaces', ['id' => $this->workspace->id]);
    });

    test('soft-deleting workspace soft-deletes multiple projects', function () {
        $projects = Project::factory()->count(3)->forWorkspace($this->workspace)->create();

        $this->workspace->delete();

        foreach ($projects as $project) {
            $this->assertSoftDeleted('projects', ['id' => $project->id]);
        }
    });

    test('hard-deleting workspace hard-deletes its projects via DB cascade', function () {
        $project = Project::factory()->forWorkspace($this->workspace)->create();
        $projectId = $project->id;

        $this->workspace->forceDelete();

        $this->assertDatabaseMissing('projects', ['id' => $projectId]);
    });

    test('soft-deleting workspace does not affect projects in other workspaces', function () {
        $other = Workspace::factory()->create();
        $otherProject = Project::factory()->forWorkspace($other)->create();

        $this->workspace->delete();

        $this->assertDatabaseHas('projects', ['id' => $otherProject->id, 'deleted_at' => null]);
    });
});

// ---------------------------------------------------------------------------
// Restore endpoint
// ---------------------------------------------------------------------------

describe('ProjectController - Restore', function () {
    test('admin can restore soft-deleted project', function () {
        diSignIn($this->user);
        $project = Project::factory()->forWorkspace($this->workspace)->create();
        $project->delete();

        $this->withHeaders(diWsHeader($this->workspace))
            ->postJson("/api/v1/projects/{$project->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $project->id);

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'deleted_at' => null]);
    });

    test('restore returns 422 when active project has same name (name conflict)', function () {
        diSignIn($this->user);
        $deleted = Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Conflict']);
        $deleted->delete();

        // Create new active project with same name (allowed now that soft-delete reuse works)
        Project::factory()->forWorkspace($this->workspace)->create(['name' => 'Conflict']);

        $this->withHeaders(diWsHeader($this->workspace))
            ->postJson("/api/v1/projects/{$deleted->id}/restore")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    });

    test('restore returns 422 if project is not deleted (already active)', function () {
        diSignIn($this->user);
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        $this->withHeaders(diWsHeader($this->workspace))
            ->postJson("/api/v1/projects/{$project->id}/restore")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    });

    test('restore returns 404 for project in other workspace', function () {
        diSignIn($this->user);
        $other = Workspace::factory()->create();
        $project = Project::factory()->forWorkspace($other)->create();
        $project->delete();

        $this->withHeaders(diWsHeader($this->workspace))
            ->postJson("/api/v1/projects/{$project->id}/restore")
            ->assertNotFound();
    });

    test('restore returns 403 for member who does not own the project', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $this->workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        Sanctum::actingAs($owner);
        $project = Project::factory()->forWorkspace($this->workspace)->create();
        $project->delete();

        diSignIn($member);

        $this->withHeaders(diWsHeader($this->workspace))
            ->postJson("/api/v1/projects/{$project->id}/restore")
            ->assertForbidden();
    });

    test('member can restore their own soft-deleted project', function () {
        $member = User::factory()->create();
        $this->workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        diSignIn($member);
        $project = Project::factory()->forWorkspace($this->workspace)->create();
        $project->delete();

        $this->withHeaders(diWsHeader($this->workspace))
            ->postJson("/api/v1/projects/{$project->id}/restore")
            ->assertOk();
    });

    test('restore requires authentication', function () {
        $project = Project::factory()->forWorkspace($this->workspace)->create();
        $project->delete();

        test()->withHeaders(diWsHeader($this->workspace))
            ->postJson("/api/v1/projects/{$project->id}/restore")
            ->assertUnauthorized();
    });

    test('restore returns 404 when workspace context is missing', function () {
        diSignIn($this->user);
        $project = Project::factory()->forWorkspace($this->workspace)->create();
        $project->delete();

        $this->postJson("/api/v1/projects/{$project->id}/restore")
            ->assertNotFound();
    });
});
