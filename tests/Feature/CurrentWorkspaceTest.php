<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('CurrentWorkspace Service', function () {
    it('creates service with workspace', function () {
        $workspace = Workspace::factory()->create();
        $service = new CurrentWorkspace($workspace);

        expect($service->workspace)->toBeInstanceOf(Workspace::class);
        expect($service->workspace->id)->toBe($workspace->id);
    });

    it('returns workspace id', function () {
        $workspace = Workspace::factory()->create();
        $service = new CurrentWorkspace($workspace);

        expect($service->id())->toBe($workspace->id);
    });

    it('returns workspace slug', function () {
        $workspace = Workspace::factory()->create(['slug' => 'test-slug']);
        $service = new CurrentWorkspace($workspace);

        expect($service->slug())->toBe('test-slug');
    });

    it('returns workspace name', function () {
        $workspace = Workspace::factory()->create(['name' => 'Test Workspace']);
        $service = new CurrentWorkspace($workspace);

        expect($service->name())->toBe('Test Workspace');
    });

    it('returns workspace domain', function () {
        $workspace = Workspace::factory()->withDomain()->create();
        $service = new CurrentWorkspace($workspace);

        expect($service->domain())->not->toBeNull();
    });

    it('returns workspace status', function () {
        $workspace = Workspace::factory()->create(['status' => 'active']);
        $service = new CurrentWorkspace($workspace);

        expect($service->status())->toBe('active');
    });

    it('checks if workspace is active', function () {
        $active = Workspace::factory()->create(['status' => 'active']);
        $suspended = Workspace::factory()->suspended()->create();

        expect((new CurrentWorkspace($active))->isActive())->toBeTrue();
        expect((new CurrentWorkspace($suspended))->isActive())->toBeFalse();
    });

    it('checks if workspace is suspended', function () {
        $suspended = Workspace::factory()->suspended()->create();
        $active = Workspace::factory()->create();

        expect((new CurrentWorkspace($suspended))->isSuspended())->toBeTrue();
        expect((new CurrentWorkspace($active))->isSuspended())->toBeFalse();
    });

    it('checks if workspace is archived', function () {
        $archived = Workspace::factory()->archived()->create();
        $active = Workspace::factory()->create();

        expect((new CurrentWorkspace($archived))->isArchived())->toBeTrue();
        expect((new CurrentWorkspace($active))->isArchived())->toBeFalse();
    });

    it('compares workspaces with is method', function () {
        $workspace1 = Workspace::factory()->create();
        $workspace2 = Workspace::factory()->create();
        $service = new CurrentWorkspace($workspace1);

        expect($service->is($workspace1))->toBeTrue();
        expect($service->is($workspace2))->toBeFalse();
    });

    it('returns owner role for workspace owner', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->addMember($user, 'owner');
        $service = new CurrentWorkspace($workspace);

        $this->actingAs($user);

        expect($service->userRole())->toBe('owner');
    });

    it('returns null role when user is not authenticated', function () {
        $workspace = Workspace::factory()->create();
        $service = new CurrentWorkspace($workspace);

        expect($service->userRole())->toBeNull();
    });

    it('returns null role when user is not owner', function () {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $service = new CurrentWorkspace($workspace);

        $this->actingAs($otherUser);

        expect($service->userRole())->toBeNull();
    });

    it('checks owner permissions with userCan', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->addMember($user, 'owner');
        $service = new CurrentWorkspace($workspace);

        $this->actingAs($user);

        expect($service->userCan('any-permission'))->toBeTrue();
    });

    it('denies permissions for non-authenticated users', function () {
        $workspace = Workspace::factory()->create();
        $service = new CurrentWorkspace($workspace);

        expect($service->userCan('any-permission'))->toBeFalse();
    });

    it('denies permissions for non-owners', function () {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $service = new CurrentWorkspace($workspace);

        $this->actingAs($otherUser);

        expect($service->userCan('any-permission'))->toBeFalse();
    });
});

describe('Helper Functions', function () {
    it('returns null when no workspace is bound', function () {
        expect(current_workspace())->toBeNull();
        expect(current_workspace_id())->toBeNull();
        expect(current_workspace_slug())->toBeNull();
    });

    it('returns current workspace when bound', function () {
        $workspace = Workspace::factory()->create();
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));

        expect(current_workspace())->toBeInstanceOf(CurrentWorkspace::class);
        expect(current_workspace()->id())->toBe($workspace->id);
    });

    it('returns current workspace id', function () {
        $workspace = Workspace::factory()->create();
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));

        expect(current_workspace_id())->toBe($workspace->id);
    });

    it('returns current workspace slug', function () {
        $workspace = Workspace::factory()->create(['slug' => 'helper-test']);
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));

        expect(current_workspace_slug())->toBe('helper-test');
    });

    it('checks workspace ownership with workspace_owns', function () {
        $workspace = Workspace::factory()->create();
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));

        $ownedModel = (object) ['workspace_id' => $workspace->id];
        $otherModel = (object) ['workspace_id' => 999];

        expect(workspace_owns($ownedModel))->toBeTrue();
        expect(workspace_owns($otherModel))->toBeFalse();
    });

    it('returns false for workspace_owns when no workspace is set', function () {
        $model = (object) ['workspace_id' => 1];

        expect(workspace_owns($model))->toBeFalse();
    });

    it('returns false for workspace_owns when model has no workspace_id', function () {
        $workspace = Workspace::factory()->create();
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));

        $model = (object) ['other_field' => 'value'];

        expect(workspace_owns($model))->toBeFalse();
    });
});

describe('Service Container Binding', function () {
    it('binds service as singleton', function () {
        $workspace = Workspace::factory()->create();
        $service = new CurrentWorkspace($workspace);

        app()->instance(CurrentWorkspace::class, $service);

        $firstCall = app(CurrentWorkspace::class);
        $secondCall = app(CurrentWorkspace::class);

        expect($firstCall)->toBe($secondCall);
        expect($firstCall->id())->toBe($workspace->id);
    });

    it('can rebind to different workspace', function () {
        $workspace1 = Workspace::factory()->create();
        $workspace2 = Workspace::factory()->create();

        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace1));
        expect(current_workspace_id())->toBe($workspace1->id);

        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace2));
        expect(current_workspace_id())->toBe($workspace2->id);
    });
});
