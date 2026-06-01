<?php

use App\Http\Resources\ProjectCollection;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrentWorkspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function bindProjectWorkspaceContext(Workspace $workspace): void
{
    app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));
}

describe('Project migration and model foundation', function () {
    test('projects table contains expected columns and indexes', function () {
        expect(Schema::hasTable('projects'))->toBeTrue();

        expect(Schema::hasColumns('projects', [
            'id',
            'workspace_id',
            'name',
            'description',
            'status',
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
            'deleted_at',
        ]))->toBeTrue();

        $indexNames = collect(Schema::getIndexes('projects'))->pluck('name');

        // Story 5.4: projects_workspace_id_name_unique was intentionally dropped.
        // Workspace-scoped name uniqueness is now enforced at application level
        // via App\Rules\UniqueInWorkspace (excludes soft-deleted rows).
        expect($indexNames)->toContain('projects_workspace_id_index')
            ->toContain('projects_status_index')
            ->toContain('projects_created_by_index')
            ->not->toContain('projects_workspace_id_name_unique');
    });

    test('status column enforces allowed enum values', function () {
        $workspace = Workspace::factory()->create();
        bindProjectWorkspaceContext($workspace);

        Project::create([
            'name' => 'Valid Status Project',
            'status' => 'active',
        ]);

        expect(fn () => Project::create([
            'name' => 'Invalid Status Project',
            'status' => 'not-valid',
        ]))->toThrow(QueryException::class);
    });

    test('workspace_id is scoped correctly and allows same name in different workspaces', function () {
        // Story 5.4: The DB-level unique constraint (workspace_id, name) was intentionally
        // dropped to allow soft-deleted name reuse. Uniqueness for active projects is now
        // enforced at the application level via UniqueInWorkspace rule (tested in DataIntegrityTest).
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();

        bindProjectWorkspaceContext($workspaceA);
        $projectA = Project::create([
            'name' => 'Alpha',
            'status' => 'active',
        ]);

        bindProjectWorkspaceContext($workspaceB);
        $projectB = Project::create([
            'name' => 'Alpha',
            'status' => 'active',
        ]);

        expect($projectA->workspace_id)->toBe($workspaceA->id)
            ->and($projectB->workspace_id)->toBe($workspaceB->id);
    });

    test('workspace_id cannot be mass-assigned from payload', function () {
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();

        bindProjectWorkspaceContext($workspaceA);

        $project = Project::create([
            'name' => 'Mass Assignment Guard',
            'status' => 'active',
            'workspace_id' => $workspaceB->id,
        ]);

        expect($project->workspace_id)->toBe($workspaceA->id);
    });

    test('workspace_id is immutable after creation', function () {
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();

        bindProjectWorkspaceContext($workspaceA);

        $project = Project::create([
            'name' => 'Immutable Workspace Project',
            'status' => 'active',
        ]);

        expect(fn () => $project->forceFill([
            'workspace_id' => $workspaceB->id,
        ])->save())->toThrow(\RuntimeException::class, 'workspace_id cannot be modified');

        $reloaded = Project::acrossWorkspaces()->find($project->id);

        expect($reloaded)->not->toBeNull()
            ->and($reloaded?->workspace_id)->toBe($workspaceA->id);
    });

    test('workspace scope filters by current context and can be bypassed explicitly', function () {
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();

        Project::factory()->forWorkspace($workspaceA)->create(['name' => 'Alpha']);
        Project::factory()->forWorkspace($workspaceB)->create(['name' => 'Beta']);

        bindProjectWorkspaceContext($workspaceA);

        expect(Project::query()->pluck('name')->all())->toBe(['Alpha'])
            ->and(Project::acrossWorkspaces()->pluck('name')->all())->toContain('Alpha', 'Beta')
            ->and(Project::forAllWorkspaces()->pluck('name')->all())->toContain('Alpha', 'Beta');
    });

    test('workspace scope does not filter queries when no current workspace context is bound', function () {
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();

        Project::factory()->forWorkspace($workspaceA)->create();
        Project::factory()->forWorkspace($workspaceB)->create();

        app()->forgetInstance(CurrentWorkspace::class);

        expect(current_workspace_id())->toBeNull()
            ->and(Project::count())->toBe(2);
    });

    test('project model exposes workspace, creator and updater relationships', function () {
        $workspace = Workspace::factory()->create();
        $creator = User::factory()->create();
        $updater = User::factory()->create();

        $project = Project::factory()
            ->forWorkspace($workspace)
            ->forCreator($creator)
            ->create([
                'updated_by' => $updater->id,
            ]);

        expect($project->workspace->is($workspace))->toBeTrue()
            ->and($project->creator?->is($creator))->toBeTrue()
            ->and($project->updater?->is($updater))->toBeTrue();
    });

    test('project factory supports status states and workspace scoped helpers', function () {
        $workspace = Workspace::factory()->create();
        $creator = User::factory()->create();

        $active = Project::factory()->forWorkspace($workspace)->forCreator($creator)->active()->create();
        $archived = Project::factory()->forWorkspace($workspace)->archived()->create();
        $completed = Project::factory()->forWorkspace($workspace)->completed()->create();

        expect($active->workspace_id)->toBe($workspace->id)
            ->and($active->created_by)->toBe($creator->id)
            ->and($active->status)->toBe('active')
            ->and($archived->status)->toBe('archived')
            ->and($completed->status)->toBe('completed');
    });
});

describe('Project observer and resources', function () {
    test('observer sets created_by on create and updated_by on update when authenticated', function () {
        $workspace = Workspace::factory()->create();
        $creator = User::factory()->create();
        $updater = User::factory()->create();

        bindProjectWorkspaceContext($workspace);

        $this->actingAs($creator);

        $project = Project::create([
            'name' => 'Observed Project',
            'status' => 'active',
        ]);

        expect($project->created_by)->toBe($creator->id)
            ->and($project->updated_by)->toBeNull();

        $this->actingAs($updater);

        $project->update([
            'name' => 'Observed Project Updated',
        ]);

        $project->refresh();

        expect($project->updated_by)->toBe($updater->id);
    });

    test('project resource serializes base and nested fields', function () {
        $workspace = Workspace::factory()->create();
        $creator = User::factory()->create();
        $updater = User::factory()->create();

        $project = Project::factory()
            ->forWorkspace($workspace)
            ->forCreator($creator)
            ->create([
                'name' => 'Resource Project',
                'status' => 'active',
                'updated_by' => $updater->id,
            ])
            ->load(['workspace', 'creator', 'updater']);

        $resource = (new ProjectResource($project))->toArray(request());

        expect($resource)->toHaveKeys([
            'id',
            'workspace_id',
            'name',
            'description',
            'status',
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
            'creator',
            'updater',
            'workspace',
        ]);

        expect($resource['workspace_id'])->toBe($workspace->id)
            ->and($resource['created_by'])->toBe($creator->id)
            ->and($resource['updated_by'])->toBe($updater->id)
            ->and($resource['creator']['id'])->toBe($creator->id)
            ->and($resource['updater']['id'])->toBe($updater->id)
            ->and($resource['workspace']['id'])->toBe($workspace->id);
    });

    test('project collection includes data and pagination meta', function () {
        $workspace = Workspace::factory()->create();
        bindProjectWorkspaceContext($workspace);

        Project::factory()->count(3)->forWorkspace($workspace)->create();

        $paginator = Project::query()->latest('id')->paginate(2);
        $collection = (new ProjectCollection($paginator))->toArray(request());

        expect($collection)->toHaveKeys(['data', 'meta'])
            ->and($collection['data'])->toHaveCount(2)
            ->and($collection['meta']['total'])->toBe(3)
            ->and($collection['meta']['per_page'])->toBe(2)
            ->and($collection['meta']['current_page'])->toBe(1)
            ->and($collection['meta']['last_page'])->toBe(2);
    });

    test('observer overrides forged created_by with authenticated user', function () {
        $workspace = Workspace::factory()->create();
        $explicitCreator = User::factory()->create();
        $actingUser = User::factory()->create();

        bindProjectWorkspaceContext($workspace);
        $this->actingAs($actingUser);

        // Use unguarded to actually bypass the fillable guard and inject the forged value,
        // proving the observer overrides it rather than the guard silently dropping it.
        $project = Project::unguarded(fn () => Project::create([
            'name' => 'Forged Creator Project',
            'status' => 'active',
            'created_by' => $explicitCreator->id,
        ]));

        expect($project->created_by)->toBe($actingUser->id);
    });

    test('observer does not set updated_by when soft-deleting', function () {
        $workspace = Workspace::factory()->create();
        $creator = User::factory()->create();
        $deleter = User::factory()->create();

        bindProjectWorkspaceContext($workspace);
        $this->actingAs($creator);

        $project = Project::create([
            'name' => 'Soft Delete Observer Test',
            'status' => 'active',
        ]);

        expect($project->updated_by)->toBeNull();

        $this->actingAs($deleter);
        $project->delete();
        $project->refresh();

        // updated_by should remain null — soft-delete must not be treated as an edit
        expect($project->updated_by)->toBeNull();
    });

    test('projects use soft deletes and are excluded from default queries', function () {
        $project = Project::factory()->create();

        $project->delete();

        expect(Project::count())->toBe(0)
            ->and(Project::withTrashed()->count())->toBe(1);
    });

    test('force deleting a workspace cascades project deletion', function () {
        $workspace = Workspace::factory()->create();
        $projects = Project::factory()->count(2)->forWorkspace($workspace)->create();

        $projectIds = $projects->pluck('id');
        $workspace->forceDelete();

        expect(Project::withTrashed()->whereIn('id', $projectIds)->exists())->toBeFalse();
    });

    test('deleting users sets created_by and updated_by to null', function () {
        $workspace = Workspace::factory()->create();
        $creator = User::factory()->create();
        $updater = User::factory()->create();

        $project = Project::factory()
            ->forWorkspace($workspace)
            ->forCreator($creator)
            ->create([
                'updated_by' => $updater->id,
            ]);

        $creator->delete();
        $updater->delete();
        $project->refresh();

        expect($project->created_by)->toBeNull()
            ->and($project->updated_by)->toBeNull();
    });
});
