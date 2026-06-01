<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\BaseWorkspacePolicy;
use App\Policies\ProjectPolicy;
use App\Services\CurrentWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

class TestableWorkspacePolicy extends BaseWorkspacePolicy
{
    public function workspaceProxy(): ?Workspace
    {
        return $this->workspace();
    }

    public function isOwnerProxy(User $user): bool
    {
        return $this->isOwner($user);
    }

    public function isAdminProxy(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function isMemberProxy(User $user): bool
    {
        return $this->isMember($user);
    }

    public function hasPermissionProxy(User $user, string $permission): bool
    {
        return $this->hasPermission($user, $permission);
    }

    public function belongsToCurrentWorkspaceProxy($model): bool
    {
        return $this->belongsToCurrentWorkspace($model);
    }
}

function bindWorkspaceContext(Workspace $workspace): void
{
    app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));
}

describe('Workspace-aware base policy helpers', function () {
    test('workspace helper returns current context and null when not bound', function () {
        $workspace = Workspace::factory()->create();
        $policy = new TestableWorkspacePolicy;

        app()->forgetInstance(CurrentWorkspace::class);
        expect($policy->workspaceProxy())->toBeNull();

        bindWorkspaceContext($workspace);
        expect($policy->workspaceProxy()?->id)->toBe($workspace->id);
    });

    test('isOwner, isAdmin, isMember and hasPermission resolve from current workspace', function () {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $guest = User::factory()->create();
        $outsider = User::factory()->create();

        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');
        $workspace->addMember($admin, 'admin');
        $workspace->addMember($member, 'member');
        $workspace->addMember($guest, 'guest');

        bindWorkspaceContext($workspace);
        $policy = new TestableWorkspacePolicy;

        expect($policy->isOwnerProxy($owner))->toBeTrue();
        expect($policy->isOwnerProxy($admin))->toBeFalse();
        expect($policy->isAdminProxy($owner))->toBeTrue();
        expect($policy->isAdminProxy($admin))->toBeTrue();
        expect($policy->isAdminProxy($member))->toBeFalse();
        expect($policy->isMemberProxy($guest))->toBeTrue();
        expect($policy->isMemberProxy($outsider))->toBeFalse();

        expect($policy->hasPermissionProxy($owner, 'resources.delete'))->toBeTrue();
        expect($policy->hasPermissionProxy($member, 'resources.create'))->toBeTrue();
        expect($policy->hasPermissionProxy($guest, 'resources.create'))->toBeFalse();
    });

    test('belongsToCurrentWorkspace checks workspace ownership on models', function () {
        $owner = User::factory()->create();
        $workspaceA = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspaceB = Workspace::factory()->create();
        $workspaceA->addMember($owner, 'owner');

        $projectA = Project::factory()->create(['workspace_id' => $workspaceA->id]);
        $projectB = Project::factory()->create(['workspace_id' => $workspaceB->id]);

        bindWorkspaceContext($workspaceA);
        $policy = new TestableWorkspacePolicy;

        expect($policy->belongsToCurrentWorkspaceProxy($projectA))->toBeTrue();
        expect($policy->belongsToCurrentWorkspaceProxy($projectB))->toBeFalse();
    });
});

describe('ProjectPolicy workspace-aware authorization', function () {
    test('viewAny allows members and denies non-members', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');
        $workspace->addMember($member, 'member');

        bindWorkspaceContext($workspace);
        $policy = new ProjectPolicy;

        expect($policy->viewAny($owner))->toBeTrue();
        expect($policy->viewAny($member))->toBeTrue();
        expect($policy->viewAny($outsider))->toBeFalse();
    });

    test('view enforces same workspace first', function () {
        $owner = User::factory()->create();
        $workspaceA = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspaceB = Workspace::factory()->create();
        $workspaceA->addMember($owner, 'owner');

        $projectA = Project::factory()->create(['workspace_id' => $workspaceA->id]);
        $projectB = Project::factory()->create(['workspace_id' => $workspaceB->id]);

        bindWorkspaceContext($workspaceA);
        $policy = new ProjectPolicy;

        expect($policy->view($owner, $projectA))->toBeTrue();
        expect($policy->view($owner, $projectB))->toBeFalse();
    });

    test('create requires resources.create permission', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $guest = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');
        $workspace->addMember($member, 'member');
        $workspace->addMember($guest, 'guest');

        bindWorkspaceContext($workspace);
        $policy = new ProjectPolicy;

        expect($policy->create($owner))->toBeTrue();
        expect($policy->create($member))->toBeTrue();
        expect($policy->create($guest))->toBeFalse();
    });

    test('update and delete allow admins and owners, deny members on projects they do not own', function () {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $otherCreator = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');
        $workspace->addMember($admin, 'admin');
        $workspace->addMember($member, 'member');

        $project = Project::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $otherCreator->id,
        ]);

        bindWorkspaceContext($workspace);
        $policy = new ProjectPolicy;

        expect($policy->update($owner, $project))->toBeTrue();
        expect($policy->delete($owner, $project))->toBeTrue();
        expect($policy->update($admin, $project))->toBeTrue();
        expect($policy->delete($admin, $project))->toBeTrue();
        expect($policy->update($member, $project))->toBeFalse();
        expect($policy->delete($member, $project))->toBeFalse();
    });

    test('member can update and delete own project when ownership is persisted', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');
        $workspace->addMember($member, 'member');

        $project = Project::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $member->id,
        ]);

        bindWorkspaceContext($workspace);
        $policy = new ProjectPolicy;

        expect($policy->update($member, $project))->toBeTrue();
        expect($policy->delete($member, $project))->toBeTrue();
    });
});

describe('Policy registration and authorization invocation', function () {
    test('Gate resolves ProjectPolicy and authorize denies with 403 behavior', function () {
        $owner = User::factory()->create();
        $guest = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');
        $workspace->addMember($guest, 'guest');

        bindWorkspaceContext($workspace);
        Sanctum::actingAs($guest);

        Route::middleware('auth:sanctum')->get('/_test/policies/projects/create', function () {
            Gate::authorize('create', Project::class);

            return response()->json(['ok' => true]);
        });

        $response = $this->getJson('/_test/policies/projects/create');
        $response->assertForbidden();
    });

    test('Gate::forUser uses ProjectPolicy for create checks', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');
        $workspace->addMember($member, 'member');

        bindWorkspaceContext($workspace);

        expect(Gate::forUser($member)->allows('create', Project::class))->toBeTrue();
    });

    test('Gate::forUser authorize throws for denied abilities', function () {
        $owner = User::factory()->create();
        $guest = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');
        $workspace->addMember($guest, 'guest');

        bindWorkspaceContext($workspace);

        expect(fn () => Gate::forUser($guest)->authorize('create', Project::class))
            ->toThrow(AuthorizationException::class);
    });
});
