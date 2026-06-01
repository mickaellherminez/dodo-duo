<?php

use App\Enums\WorkspaceRole;
use App\Http\Middleware\RequireWorkspacePermission;
use App\Http\Middleware\RequireWorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// AC 1: WorkspaceRole Enum
// ---------------------------------------------------------------------------

describe('RBAC - WorkspaceRole Enum', function () {
    test('permissions() returns correct arrays per role', function () {
        expect(WorkspaceRole::OWNER->permissions())->toBe(['*']);
        expect(WorkspaceRole::ADMIN->permissions())->toContain('workspace.view', 'members.invite', 'resources.*');
        expect(WorkspaceRole::MEMBER->permissions())->toContain('workspace.view', 'resources.create');
        expect(WorkspaceRole::MEMBER->permissions())->not->toContain('members.invite');
        expect(WorkspaceRole::GUEST->permissions())->toBe(['workspace.view', 'members.view', 'resources.view']);
    });

    test('can() returns true for owner on any permission via wildcard', function () {
        expect(WorkspaceRole::OWNER->can('anything'))->toBeTrue();
        expect(WorkspaceRole::OWNER->can('resources.create'))->toBeTrue();
        expect(WorkspaceRole::OWNER->can('workspace.delete'))->toBeTrue();
    });

    test('can() matches exact permissions', function () {
        expect(WorkspaceRole::MEMBER->can('resources.create'))->toBeTrue();
        expect(WorkspaceRole::MEMBER->can('resources.view'))->toBeTrue();
        expect(WorkspaceRole::MEMBER->can('members.invite'))->toBeFalse();
        expect(WorkspaceRole::GUEST->can('resources.view'))->toBeTrue();
        expect(WorkspaceRole::GUEST->can('resources.create'))->toBeFalse();
    });

    test('can() matches prefix wildcard (resources.*)', function () {
        // Admin has resources.* so should match any resources.X permission
        expect(WorkspaceRole::ADMIN->can('resources.create'))->toBeTrue();
        expect(WorkspaceRole::ADMIN->can('resources.view'))->toBeTrue();
        expect(WorkspaceRole::ADMIN->can('resources.delete-any'))->toBeTrue();
        // Non-matching prefix
        expect(WorkspaceRole::ADMIN->can('workspace.delete'))->toBeFalse();
    });

    test('isAtLeast() respects hierarchy (OWNER > ADMIN > MEMBER > GUEST)', function () {
        expect(WorkspaceRole::OWNER->isAtLeast(WorkspaceRole::OWNER))->toBeTrue();
        expect(WorkspaceRole::OWNER->isAtLeast(WorkspaceRole::ADMIN))->toBeTrue();
        expect(WorkspaceRole::OWNER->isAtLeast(WorkspaceRole::MEMBER))->toBeTrue();
        expect(WorkspaceRole::OWNER->isAtLeast(WorkspaceRole::GUEST))->toBeTrue();

        expect(WorkspaceRole::ADMIN->isAtLeast(WorkspaceRole::OWNER))->toBeFalse();
        expect(WorkspaceRole::ADMIN->isAtLeast(WorkspaceRole::ADMIN))->toBeTrue();
        expect(WorkspaceRole::ADMIN->isAtLeast(WorkspaceRole::MEMBER))->toBeTrue();
        expect(WorkspaceRole::ADMIN->isAtLeast(WorkspaceRole::GUEST))->toBeTrue();

        expect(WorkspaceRole::MEMBER->isAtLeast(WorkspaceRole::OWNER))->toBeFalse();
        expect(WorkspaceRole::MEMBER->isAtLeast(WorkspaceRole::ADMIN))->toBeFalse();
        expect(WorkspaceRole::MEMBER->isAtLeast(WorkspaceRole::MEMBER))->toBeTrue();
        expect(WorkspaceRole::MEMBER->isAtLeast(WorkspaceRole::GUEST))->toBeTrue();

        expect(WorkspaceRole::GUEST->isAtLeast(WorkspaceRole::OWNER))->toBeFalse();
        expect(WorkspaceRole::GUEST->isAtLeast(WorkspaceRole::ADMIN))->toBeFalse();
        expect(WorkspaceRole::GUEST->isAtLeast(WorkspaceRole::MEMBER))->toBeFalse();
        expect(WorkspaceRole::GUEST->isAtLeast(WorkspaceRole::GUEST))->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// AC 2: HasWorkspaceRole trait on User
// ---------------------------------------------------------------------------

describe('RBAC - HasWorkspaceRole trait', function () {
    test('getWorkspaceRole() returns correct enum for member', function () {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');

        expect($owner->getWorkspaceRole($workspace))->toBe(WorkspaceRole::OWNER);
    });

    test('getWorkspaceRole() returns null for non-member', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();

        expect($user->getWorkspaceRole($workspace))->toBeNull();
    });

    test('getWorkspaceRole() returns correct role for each role type', function () {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        foreach (['admin', 'member', 'guest'] as $role) {
            $user = User::factory()->create();
            $workspace->addMember($user, $role);
            expect($user->getWorkspaceRole($workspace))->toBe(WorkspaceRole::from($role));
        }
    });

    test('hasWorkspaceRole() returns true only for exact match', function () {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');

        expect($owner->hasWorkspaceRole(WorkspaceRole::OWNER, $workspace))->toBeTrue();
        expect($owner->hasWorkspaceRole(WorkspaceRole::ADMIN, $workspace))->toBeFalse();
    });

    test('isWorkspaceOwner() returns true for owner only', function () {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');
        $workspace->addMember($admin, 'admin');

        expect($owner->isWorkspaceOwner($workspace))->toBeTrue();
        expect($admin->isWorkspaceOwner($workspace))->toBeFalse();
    });

    test('isWorkspaceAdmin() returns true for admin and owner', function () {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');
        $workspace->addMember($admin, 'admin');
        $workspace->addMember($member, 'member');

        expect($owner->isWorkspaceAdmin($workspace))->toBeTrue();
        expect($admin->isWorkspaceAdmin($workspace))->toBeTrue();
        expect($member->isWorkspaceAdmin($workspace))->toBeFalse();
    });

    test('canInWorkspace() returns correct result per role and permission', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $guest = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');
        $workspace->addMember($member, 'member');
        $workspace->addMember($guest, 'guest');

        // Owner can do anything
        expect($owner->canInWorkspace('workspace.delete', $workspace))->toBeTrue();
        // Member can create resources
        expect($member->canInWorkspace('resources.create', $workspace))->toBeTrue();
        // Member cannot invite
        expect($member->canInWorkspace('members.invite', $workspace))->toBeFalse();
        // Guest can only view
        expect($guest->canInWorkspace('resources.view', $workspace))->toBeTrue();
        expect($guest->canInWorkspace('resources.create', $workspace))->toBeFalse();
    });

    test('canInWorkspace() returns false for non-member', function () {
        $outsider = User::factory()->create();
        $workspace = Workspace::factory()->create();

        expect($outsider->canInWorkspace('workspace.view', $workspace))->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// AC 3: CurrentWorkspace service
// ---------------------------------------------------------------------------

describe('RBAC - CurrentWorkspace service', function () {
    test('userRole() returns the authenticated user role string', function () {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');

        $this->actingAs($owner);
        $service = new CurrentWorkspace($workspace);

        expect($service->userRole())->toBe('owner');
    });

    test('userRole() returns null for non-member', function () {
        $outsider = User::factory()->create();
        $workspace = Workspace::factory()->create();

        $this->actingAs($outsider);
        $service = new CurrentWorkspace($workspace);

        expect($service->userRole())->toBeNull();
    });

    test('userRole() returns null for unauthenticated user', function () {
        $workspace = Workspace::factory()->create();
        $service = new CurrentWorkspace($workspace);

        expect($service->userRole())->toBeNull();
    });

    test('userCan() delegates to WorkspaceRole::can()', function () {
        $admin = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->addMember($admin, 'admin');

        $this->actingAs($admin);
        $service = new CurrentWorkspace($workspace);

        expect($service->userCan('resources.create'))->toBeTrue();
        expect($service->userCan('workspace.delete'))->toBeFalse();
    });

    test('userCan() returns false for non-member', function () {
        $outsider = User::factory()->create();
        $workspace = Workspace::factory()->create();

        $this->actingAs($outsider);
        $service = new CurrentWorkspace($workspace);

        expect($service->userCan('workspace.view'))->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// AC 4 & 5: RequireWorkspaceRole and RequireWorkspacePermission middleware
// (tested by direct middleware invocation)
// ---------------------------------------------------------------------------

describe('RBAC - RequireWorkspaceRole middleware', function () {
    test('allows request when user role matches', function () {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');

        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));

        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $owner);

        $middleware = new RequireWorkspaceRole;
        $response = $middleware->handle($request, fn ($req) => response()->json(['ok' => true]), 'owner');

        expect($response->getStatusCode())->toBe(200);
    });

    test('allows request when user role is one of multiple allowed roles', function () {
        $admin = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->addMember($admin, 'admin');

        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));

        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $admin);

        $middleware = new RequireWorkspaceRole;
        $response = $middleware->handle($request, fn ($req) => response()->json(['ok' => true]), 'owner', 'admin');

        expect($response->getStatusCode())->toBe(200);
    });

    test('blocks request when user role does not match', function () {
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->addMember($member, 'member');

        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));

        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $member);

        $middleware = new RequireWorkspaceRole;

        expect(fn () => $middleware->handle($request, fn ($req) => response()->json(['ok' => true]), 'owner'))
            ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    });

    test('blocks non-member with 403', function () {
        $outsider = User::factory()->create();
        $workspace = Workspace::factory()->create();

        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));

        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $outsider);

        $middleware = new RequireWorkspaceRole;

        expect(fn () => $middleware->handle($request, fn ($req) => response()->json(['ok' => true]), 'owner'))
            ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    });

    test('returns 400 when no workspace context is set', function () {
        app()->forgetInstance(CurrentWorkspace::class);

        $user = User::factory()->create();
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = new RequireWorkspaceRole;
        $response = $middleware->handle($request, fn ($req) => response()->json(['ok' => true]), 'owner');

        expect($response->getStatusCode())->toBe(400);
        expect($response->getData(true)['message'])->toBe('No workspace context set');
    });
});

describe('RBAC - RequireWorkspacePermission middleware', function () {
    test('allows request when user has the permission', function () {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');

        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));

        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $owner);

        $middleware = new RequireWorkspacePermission;
        $response = $middleware->handle($request, fn ($req) => response()->json(['ok' => true]), 'resources.create');

        expect($response->getStatusCode())->toBe(200);
    });

    test('blocks request when user lacks permission', function () {
        $guest = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->addMember($guest, 'guest');

        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));

        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $guest);

        $middleware = new RequireWorkspacePermission;

        expect(fn () => $middleware->handle($request, fn ($req) => response()->json(['ok' => true]), 'resources.create'))
            ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    });

    test('allows admin through wildcard permission', function () {
        $admin = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->addMember($admin, 'admin');

        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));

        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $admin);

        $middleware = new RequireWorkspacePermission;
        $response = $middleware->handle($request, fn ($req) => response()->json(['ok' => true]), 'resources.delete-any');

        expect($response->getStatusCode())->toBe(200);
    });

    test('returns 400 when no workspace context is set', function () {
        app()->forgetInstance(CurrentWorkspace::class);

        $user = User::factory()->create();
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = new RequireWorkspacePermission;
        $response = $middleware->handle($request, fn ($req) => response()->json(['ok' => true]), 'resources.create');

        expect($response->getStatusCode())->toBe(400);
        expect($response->getData(true)['message'])->toBe('No workspace context set');
    });
});

// ---------------------------------------------------------------------------
// AC 6: Multi-workspace role isolation
// ---------------------------------------------------------------------------

describe('RBAC - Multi-workspace isolation', function () {
    test('role is scoped to the workspace passed as argument', function () {
        $user = User::factory()->create();
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();

        $workspaceA->addMember($user, 'owner');
        $workspaceB->addMember($user, 'guest');

        expect($user->getWorkspaceRole($workspaceA))->toBe(WorkspaceRole::OWNER);
        expect($user->getWorkspaceRole($workspaceB))->toBe(WorkspaceRole::GUEST);
    });

    test('permissions differ across workspaces for the same user', function () {
        $user = User::factory()->create();
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();

        $workspaceA->addMember($user, 'owner');
        $workspaceB->addMember($user, 'guest');

        expect($user->canInWorkspace('resources.create', $workspaceA))->toBeTrue();
        expect($user->canInWorkspace('resources.create', $workspaceB))->toBeFalse();
    });

    test('CurrentWorkspace context is scoped to the bound workspace', function () {
        $user = User::factory()->create();
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();

        $workspaceA->addMember($user, 'owner');
        $workspaceB->addMember($user, 'guest');

        $this->actingAs($user);

        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspaceA));
        expect(app(CurrentWorkspace::class)->userRole())->toBe('owner');

        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspaceB));
        expect(app(CurrentWorkspace::class)->userRole())->toBe('guest');
    });
});
