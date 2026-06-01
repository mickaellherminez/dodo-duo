<?php

use App\Models\Project;
use App\Models\Workspace;
use App\Scopes\WorkspaceScope;
use App\Services\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * BelongsToWorkspace Trait Tests
 *
 * Tests for workspace isolation, auto-fill, immutability, and scope filtering.
 * Story 1.4 - BelongsToWorkspace Trait & WorkspaceScope
 */
describe('WorkspaceScope - Automatic Filtering', function () {
    beforeEach(function () {
        // Create workspaces and projects for testing
        $this->workspace1 = Workspace::factory()->create(['name' => 'Workspace 1']);
        $this->workspace2 = Workspace::factory()->create(['name' => 'Workspace 2']);

        $this->project1a = Project::factory()->create([
            'workspace_id' => $this->workspace1->id,
            'name' => 'Project 1A',
        ]);
        $this->project1b = Project::factory()->create([
            'workspace_id' => $this->workspace1->id,
            'name' => 'Project 1B',
        ]);
        $this->project2a = Project::factory()->create([
            'workspace_id' => $this->workspace2->id,
            'name' => 'Project 2A',
        ]);
    });

    test('filters queries by current workspace when context is set', function () {
        // Set workspace context to workspace 1
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($this->workspace1));

        $projects = Project::all();

        expect($projects)->toHaveCount(2)
            ->and($projects->pluck('id')->toArray())->toBe([
                $this->project1a->id,
                $this->project1b->id,
            ]);
    });

    test('returns all records when workspace context is not set', function () {
        // No workspace context set (allows seeding/admin operations)
        $projects = Project::all();

        expect($projects)->toHaveCount(3);
    });

    test('withoutGlobalScope bypasses workspace filtering', function () {
        // Set workspace context to workspace 1
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($this->workspace1));

        // Bypass scope - should return all projects
        $projects = Project::withoutGlobalScope(WorkspaceScope::class)->get();

        expect($projects)->toHaveCount(3);
    });

    test('acrossWorkspaces scope bypasses workspace filtering', function () {
        // Set workspace context to workspace 1
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($this->workspace1));

        // Use acrossWorkspaces scope - should return all projects
        $projects = Project::acrossWorkspaces()->get();

        expect($projects)->toHaveCount(3);
    });

    test('find() method respects workspace scope', function () {
        // Set workspace context to workspace 1
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($this->workspace1));

        // Try to find project from workspace 2 (should return null due to scope)
        $project = Project::find($this->project2a->id);

        expect($project)->toBeNull();

        // Find project from current workspace (should succeed)
        $project = Project::find($this->project1a->id);

        expect($project)->not->toBeNull()
            ->and($project->id)->toBe($this->project1a->id);
    });
});

describe('BelongsToWorkspace - Auto-fill workspace_id on Create', function () {
    test('automatically sets workspace_id from current workspace context', function () {
        $workspace = Workspace::factory()->create();
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));

        $project = Project::create([
            'name' => 'Auto-filled Project',
            'description' => 'Test auto-fill',
        ]);

        expect($project->workspace_id)->toBe($workspace->id);
    });

    test('respects explicitly provided workspace_id', function () {
        $workspace1 = Workspace::factory()->create();
        $workspace2 = Workspace::factory()->create();

        // Set context to workspace 1
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace1));

        // Explicit workspace_id is still possible for seed/admin code paths.
        $project = Project::unguarded(fn () => Project::create([
            'name' => 'Explicit Workspace',
            'workspace_id' => $workspace2->id,
        ]));

        expect($project->workspace_id)->toBe($workspace2->id);
    });

    test('throws exception when creating without workspace context', function () {
        // No workspace context set, no explicit workspace_id
        Project::create([
            'name' => 'No Workspace',
        ]);
    })->throws(RuntimeException::class, 'Cannot create model without workspace context');
});

describe('BelongsToWorkspace - Immutable workspace_id', function () {
    test('prevents workspace_id modification after creation', function () {
        $workspace1 = Workspace::factory()->create();
        $workspace2 = Workspace::factory()->create();

        $project = Project::factory()->create(['workspace_id' => $workspace1->id]);

        // Attempt to change workspace_id at model level (bypassing mass assignment guard)
        $project->forceFill(['workspace_id' => $workspace2->id]);
        $project->save();
    })->throws(RuntimeException::class, 'workspace_id cannot be modified after creation');

    test('allows updating other fields without touching workspace_id', function () {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        // Update other fields (should succeed)
        $project->update([
            'name' => 'Updated Name',
            'description' => 'Updated description',
        ]);

        expect($project->fresh())
            ->name->toBe('Updated Name')
            ->description->toBe('Updated description')
            ->workspace_id->toBe($workspace->id);
    });
});

describe('BelongsToWorkspace - Relationships', function () {
    test('defines workspace belongsTo relationship', function () {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->create(['workspace_id' => $workspace->id]);

        expect($project->workspace)->toBeInstanceOf(Workspace::class)
            ->and($project->workspace->id)->toBe($workspace->id);
    });
});

describe('BelongsToWorkspace - Complex Scenarios', function () {
    test('switching workspace context filters different records', function () {
        $workspace1 = Workspace::factory()->create();
        $workspace2 = Workspace::factory()->create();

        $project1 = Project::factory()->create(['workspace_id' => $workspace1->id]);
        $project2 = Project::factory()->create(['workspace_id' => $workspace2->id]);

        // Set context to workspace 1
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace1));
        expect(Project::all())->toHaveCount(1)
            ->and(Project::first()->id)->toBe($project1->id);

        // Switch context to workspace 2
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace2));
        expect(Project::all())->toHaveCount(1)
            ->and(Project::first()->id)->toBe($project2->id);
    });

    test('workspace scope works with query builder methods', function () {
        $workspace = Workspace::factory()->create();
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));

        Project::factory()->create(['workspace_id' => $workspace->id, 'status' => 'active']);
        Project::factory()->create(['workspace_id' => $workspace->id, 'status' => 'completed']);
        Project::factory()->create(['workspace_id' => Workspace::factory(), 'status' => 'active']);

        // Where clause should work with scope
        $activeProjects = Project::where('status', 'active')->get();
        expect($activeProjects)->toHaveCount(1);

        // Order by should work with scope
        $projects = Project::orderBy('name', 'desc')->get();
        expect($projects)->toHaveCount(2);
    });

    test('mass assignment with workspace context', function () {
        $workspace = Workspace::factory()->create();
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));

        // Create projects using factory without workspace_id (should use context)
        $projects = collect([
            Project::create(['name' => 'Project 1', 'description' => 'Test 1']),
            Project::create(['name' => 'Project 2', 'description' => 'Test 2']),
            Project::create(['name' => 'Project 3', 'description' => 'Test 3']),
        ]);

        expect($projects)->toHaveCount(3)
            ->and($projects->every(fn ($p) => $p->workspace_id === $workspace->id))->toBeTrue();
    });
});
