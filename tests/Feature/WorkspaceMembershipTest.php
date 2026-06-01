<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Workspace Membership - Model & Relationships', function () {
    test('workspace member has workspace relationship', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        $membership = WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        expect($membership->workspace)->toBeInstanceOf(Workspace::class)
            ->and($membership->workspace->id)->toBe($workspace->id);
    });

    test('workspace member has user relationship', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        $membership = WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        expect($membership->user)->toBeInstanceOf(User::class)
            ->and($membership->user->id)->toBe($user->id);
    });

    test('workspace member casts permissions to array', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        $membership = WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'member',
            'permissions' => ['read', 'write'],
            'joined_at' => now(),
        ]);

        expect($membership->permissions)->toBeArray()
            ->and($membership->permissions)->toContain('read', 'write');
    });

    test('workspace member casts dates to datetime', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        $membership = WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'invited_at' => now()->subDay(),
            'joined_at' => now(),
        ]);

        expect($membership->invited_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
            ->and($membership->joined_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    });

    test('workspace member isOwner returns true for owner role', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        $membership = WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        expect($membership->isOwner())->toBeTrue();
    });

    test('workspace member isOwner returns false for non-owner role', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        $membership = WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'member',
            'joined_at' => now(),
        ]);

        expect($membership->isOwner())->toBeFalse();
    });

    test('workspace member isAdmin returns true for admin role', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        $membership = WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'admin',
            'joined_at' => now(),
        ]);

        expect($membership->isAdmin())->toBeTrue();
    });

    test('workspace member hasPermission returns true for owner always', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        $membership = WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'permissions' => [],
            'joined_at' => now(),
        ]);

        expect($membership->hasPermission('any-permission'))->toBeTrue();
    });

    test('workspace member hasPermission checks permissions array', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        $membership = WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'member',
            'permissions' => ['read', 'write'],
            'joined_at' => now(),
        ]);

        expect($membership->hasPermission('read'))->toBeTrue()
            ->and($membership->hasPermission('delete'))->toBeFalse();
    });
});

describe('User Workspace Relationships', function () {
    test('user has workspaces relationship', function () {
        $user = User::factory()->create();
        $workspace1 = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace2 = Workspace::factory()->create(['owner_id' => $user->id]);

        $workspace1->addMember($user, 'owner');
        $workspace2->addMember($user, 'member');

        expect($user->workspaces)->toHaveCount(2)
            ->and($user->workspaces->first())->toBeInstanceOf(Workspace::class);
    });

    test('user has ownedWorkspaces relationship', function () {
        $user = User::factory()->create();
        $ownedWorkspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $otherWorkspace = Workspace::factory()->create();

        expect($user->ownedWorkspaces)->toHaveCount(1)
            ->and($user->ownedWorkspaces->first()->id)->toBe($ownedWorkspace->id);
    });

    test('user belongsToWorkspace returns true when member', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->addMember($user, 'owner');

        expect($user->belongsToWorkspace($workspace))->toBeTrue();
    });

    test('user belongsToWorkspace returns false when not member', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $otherUser->id]);
        $workspace->addMember($otherUser, 'owner');

        expect($user->belongsToWorkspace($workspace))->toBeFalse();
    });

    test('user roleInWorkspace returns correct role', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->addMember($user, 'admin');

        expect($user->roleInWorkspace($workspace))->toBe('admin');
    });

    test('user roleInWorkspace returns null when not member', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $otherUser->id]);

        expect($user->roleInWorkspace($workspace))->toBeNull();
    });
});

describe('Workspace Membership Helpers', function () {
    test('workspace hasMember returns true when user is member', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->addMember($user, 'owner');

        expect($workspace->hasMember($user))->toBeTrue();
    });

    test('workspace hasMember returns false when user is not member', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $otherUser->id]);
        $workspace->addMember($otherUser, 'owner');

        expect($workspace->hasMember($user))->toBeFalse();
    });

    test('workspace getMemberRole returns correct role', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->addMember($user, 'admin');

        expect($workspace->getMemberRole($user))->toBe('admin');
    });

    test('workspace getMemberRole returns null for non-member', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $otherUser->id]);

        expect($workspace->getMemberRole($user))->toBeNull();
    });

    test('workspace addMember creates workspace member with role', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        $member = $workspace->addMember($user, 'member');

        expect($member)->toBeInstanceOf(WorkspaceMember::class)
            ->and($member->workspace_id)->toBe($workspace->id)
            ->and($member->user_id)->toBe($user->id)
            ->and($member->role)->toBe('member')
            ->and($member->joined_at)->not->toBeNull();
    });
});

describe('Automatic Owner Member Creation', function () {
    test('workspace creation automatically adds creator as owner member', function () {
        $user = User::factory()->create();
        $suffix = (string) time();
        $slug = 'test-workspace-'.$suffix;
        $domain = 'test-'.$suffix.'.example.com';

        $this->actingAs($user);

        $response = $this->postJson('/api/v1/workspaces', [
            'name' => 'Test Workspace',
            'slug' => $slug,
            'domain' => $domain,
        ]);

        $response->assertStatus(201);

        $workspace = Workspace::where('slug', $slug)->first();

        // Verify workspace member was created
        expect(WorkspaceMember::where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->first())
            ->not->toBeNull()
            ->role->toBe('owner')
            ->joined_at->not->toBeNull();
    });
});

describe('My Workspaces API Endpoint', function () {
    test('can list all workspaces user belongs to', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        // Workspaces user owns
        $ownedWorkspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $ownedWorkspace->addMember($user, 'owner');

        // Workspaces user is member of
        $memberWorkspace = Workspace::factory()->create(['owner_id' => $otherUser->id]);
        $memberWorkspace->addMember($user, 'member');

        // Workspace user is NOT member of
        $notMemberWorkspace = Workspace::factory()->create(['owner_id' => $otherUser->id]);
        $notMemberWorkspace->addMember($otherUser, 'owner');

        $this->actingAs($user);

        $response = $this->getJson('/api/v1/my/workspaces');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $ownedWorkspace->id])
            ->assertJsonFragment(['id' => $memberWorkspace->id])
            ->assertJsonMissing(['id' => $notMemberWorkspace->id]);
    });

    test('my workspaces includes owner and member count', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->addMember($user, 'owner');

        $this->actingAs($user);

        $response = $this->getJson('/api/v1/my/workspaces');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'status',
                        'owner',
                        'member_count',
                    ],
                ],
            ]);
    });

    test('my workspaces requires authentication', function () {
        $response = $this->getJson('/api/v1/my/workspaces');

        $response->assertStatus(401);
    });
});
