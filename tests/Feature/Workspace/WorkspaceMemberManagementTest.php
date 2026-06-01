<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────

function memberUrl(Workspace $workspace, ?User $user = null): string
{
    $base = "/api/v1/workspaces/{$workspace->id}/members";

    return $user ? "{$base}/{$user->id}" : $base;
}

// ─────────────────────────────────────────────
// AC 1 – List Members
// ─────────────────────────────────────────────

describe('Workspace Members - List (AC 1)', function () {
    test('any workspace member can view the member list', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');
        $workspace->addMember($member, 'member');

        Sanctum::actingAs($member);

        $response = $this->getJson(memberUrl($workspace));

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    });

    test('response shape includes user_id, name, email, role, joined_at', function () {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');

        Sanctum::actingAs($owner);

        $response = $this->getJson(memberUrl($workspace));

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['user_id', 'name', 'email', 'role', 'joined_at'],
                ],
            ]);
    });

    test('unauthenticated user cannot view member list', function () {
        $workspace = Workspace::factory()->create();

        $response = $this->getJson(memberUrl($workspace));

        $response->assertUnauthorized();
    });

    test('non-member cannot view member list', function () {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');

        Sanctum::actingAs($outsider);

        $response = $this->getJson(memberUrl($workspace));

        $response->assertForbidden();
    });

    test('members are ordered: owner → admin → member → guest, then by name', function () {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $memberB = User::factory()->create(['name' => 'Bob']);
        $memberA = User::factory()->create(['name' => 'Alice']);
        $guest = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $workspace->addMember($guest, 'guest');
        $workspace->addMember($memberB, 'member');
        $workspace->addMember($memberA, 'member');
        $workspace->addMember($admin, 'admin');
        $workspace->addMember($owner, 'owner');

        Sanctum::actingAs($owner);

        $response = $this->getJson(memberUrl($workspace));

        $response->assertOk();
        $data = collect($response->json('data'));
        $roles = $data->pluck('role')->values()->toArray();
        expect($roles)->toBe(['owner', 'admin', 'member', 'member', 'guest']);

        $memberNames = $data->where('role', 'member')->pluck('name')->values()->toArray();
        expect($memberNames)->toBe(['Alice', 'Bob']);
    });

    test('role filter returns only members with that role', function () {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $workspace->addMember($owner, 'owner');
        $workspace->addMember($admin, 'admin');
        $workspace->addMember($member, 'member');

        Sanctum::actingAs($owner);

        $response = $this->getJson(memberUrl($workspace).'?role=admin');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['role' => 'admin']);
    });

    test('search filter matches on name', function () {
        $owner = User::factory()->create();
        $john = User::factory()->create(['name' => 'John Doe']);
        $jane = User::factory()->create(['name' => 'Jane Smith']);
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $workspace->addMember($owner, 'owner');
        $workspace->addMember($john, 'member');
        $workspace->addMember($jane, 'member');

        Sanctum::actingAs($owner);

        $response = $this->getJson(memberUrl($workspace).'?search=John');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'John Doe']);
    });

    test('search filter matches on email', function () {
        $owner = User::factory()->create();
        $user = User::factory()->create(['email' => 'unique@example.com']);
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $workspace->addMember($owner, 'owner');
        $workspace->addMember($user, 'member');

        Sanctum::actingAs($owner);

        $response = $this->getJson(memberUrl($workspace).'?search=unique');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['email' => 'unique@example.com']);
    });

    test('soft-deleted members are not listed', function () {
        $owner = User::factory()->create();
        $removed = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $workspace->addMember($owner, 'owner');
        $membership = $workspace->addMember($removed, 'member');
        $membership->delete(); // soft delete

        Sanctum::actingAs($owner);

        $response = $this->getJson(memberUrl($workspace));

        $response->assertOk()
            ->assertJsonCount(1, 'data'); // only owner
    });

    test('member list is paginated', function () {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');

        User::factory(5)->create()->each(fn ($u) => $workspace->addMember($u, 'member'));

        Sanctum::actingAs($owner);

        $response = $this->getJson(memberUrl($workspace).'?per_page=3');

        $response->assertOk()
            ->assertJsonStructure(['meta' => ['current_page', 'last_page', 'per_page', 'total']])
            ->assertJsonPath('meta.per_page', 3)
            ->assertJsonPath('meta.total', 6);
    });

    test('member list per_page is capped', function () {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');

        User::factory(5)->create()->each(fn ($u) => $workspace->addMember($u, 'member'));

        Sanctum::actingAs($owner);

        $response = $this->getJson(memberUrl($workspace).'?per_page=500');

        $response->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    });
});

// ─────────────────────────────────────────────
// AC 2 – Member Removal
// ─────────────────────────────────────────────

describe('Workspace Members - Remove (AC 2)', function () {
    test('owner can remove a member', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $workspace->addMember($owner, 'owner');
        $workspace->addMember($member, 'member');

        Sanctum::actingAs($owner);

        $response = $this->deleteJson(memberUrl($workspace, $member));

        $response->assertNoContent();
        $this->assertSoftDeleted('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $member->id,
        ]);
    });

    test('removed member no longer appears in member list', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $workspace->addMember($owner, 'owner');
        $workspace->addMember($member, 'member');

        Sanctum::actingAs($owner);
        $this->deleteJson(memberUrl($workspace, $member));

        $response = $this->getJson(memberUrl($workspace));
        $response->assertOk()
            ->assertJsonCount(1, 'data'); // only owner left
    });

    test('non-owner cannot remove a member', function () {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $target = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $workspace->addMember($owner, 'owner');
        $workspace->addMember($admin, 'admin');
        $workspace->addMember($target, 'member');

        Sanctum::actingAs($admin);

        $response = $this->deleteJson(memberUrl($workspace, $target));

        $response->assertForbidden();
    });

    test('secondary owner can remove a member', function () {
        $primaryOwner = User::factory()->create();
        $secondaryOwner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $primaryOwner->id]);

        $workspace->addMember($primaryOwner, 'owner');
        $workspace->addMember($secondaryOwner, 'owner');
        $workspace->addMember($member, 'member');

        Sanctum::actingAs($secondaryOwner);

        $response = $this->deleteJson(memberUrl($workspace, $member));

        $response->assertNoContent();
    });

    test('cannot remove the last owner (422)', function () {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');

        Sanctum::actingAs($owner);

        $response = $this->deleteJson(memberUrl($workspace, $owner));

        $response->assertUnprocessable();
    });

    test('returns 404 when removing member from a different workspace', function () {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $otherOwner->id]);

        $workspace->addMember($owner, 'owner');
        $otherWorkspace->addMember($otherOwner, 'owner');
        $otherWorkspace->addMember($member, 'member');

        Sanctum::actingAs($owner);

        // $member belongs to otherWorkspace, not $workspace
        $response = $this->deleteJson(memberUrl($workspace, $member));

        $response->assertNotFound();
    });
});

// ─────────────────────────────────────────────
// AC 3 – Role Management
// ─────────────────────────────────────────────

describe('Workspace Members - Role Update (AC 3)', function () {
    test('owner can change member role to any valid role', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $workspace->addMember($owner, 'owner');
        $workspace->addMember($member, 'member');

        Sanctum::actingAs($owner);

        $response = $this->patchJson(memberUrl($workspace, $member), ['role' => 'admin']);

        $response->assertOk()
            ->assertJsonFragment(['role' => 'admin']);

        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $member->id,
            'role' => 'admin',
        ]);
    });

    test('secondary owner can change member role to any valid role', function () {
        $primaryOwner = User::factory()->create();
        $secondaryOwner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $primaryOwner->id]);

        $workspace->addMember($primaryOwner, 'owner');
        $workspace->addMember($secondaryOwner, 'owner');
        $workspace->addMember($member, 'member');

        Sanctum::actingAs($secondaryOwner);

        $response = $this->patchJson(memberUrl($workspace, $member), ['role' => 'admin']);

        $response->assertOk()
            ->assertJsonFragment(['role' => 'admin']);
    });

    test('owner cannot demote the last owner (422)', function () {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');

        Sanctum::actingAs($owner);

        $response = $this->patchJson(memberUrl($workspace, $owner), ['role' => 'admin']);

        $response->assertUnprocessable();
    });

    test('owner can demote one of multiple owners', function () {
        $owner1 = User::factory()->create();
        $owner2 = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner1->id]);

        $workspace->addMember($owner1, 'owner');
        $workspace->addMember($owner2, 'owner');

        Sanctum::actingAs($owner1);

        $response = $this->patchJson(memberUrl($workspace, $owner2), ['role' => 'admin']);

        $response->assertOk()
            ->assertJsonFragment(['role' => 'admin']);
    });

    test('admin can change member role to guest', function () {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $workspace->addMember($owner, 'owner');
        $workspace->addMember($admin, 'admin');
        $workspace->addMember($member, 'member');

        Sanctum::actingAs($admin);

        $response = $this->patchJson(memberUrl($workspace, $member), ['role' => 'guest']);

        $response->assertOk()
            ->assertJsonFragment(['role' => 'guest']);
    });

    test('admin can change guest role to member', function () {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $guest = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $workspace->addMember($owner, 'owner');
        $workspace->addMember($admin, 'admin');
        $workspace->addMember($guest, 'guest');

        Sanctum::actingAs($admin);

        $response = $this->patchJson(memberUrl($workspace, $guest), ['role' => 'member']);

        $response->assertOk()
            ->assertJsonFragment(['role' => 'member']);
    });

    test('admin cannot promote a member to admin (403)', function () {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $workspace->addMember($owner, 'owner');
        $workspace->addMember($admin, 'admin');
        $workspace->addMember($member, 'member');

        Sanctum::actingAs($admin);

        $response = $this->patchJson(memberUrl($workspace, $member), ['role' => 'admin']);

        $response->assertForbidden();
    });

    test('admin cannot change owner role (403)', function () {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $workspace->addMember($owner, 'owner');
        $workspace->addMember($admin, 'admin');

        Sanctum::actingAs($admin);

        $response = $this->patchJson(memberUrl($workspace, $owner), ['role' => 'member']);

        $response->assertForbidden();
    });

    test('invalid role returns 422', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $workspace->addMember($owner, 'owner');
        $workspace->addMember($member, 'member');

        Sanctum::actingAs($owner);

        $response = $this->patchJson(memberUrl($workspace, $member), ['role' => 'superuser']);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    });

    test('returns 404 when updating member from a different workspace', function () {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $otherOwner->id]);

        $workspace->addMember($owner, 'owner');
        $otherWorkspace->addMember($otherOwner, 'owner');
        $otherWorkspace->addMember($member, 'member');

        Sanctum::actingAs($owner);

        $response = $this->patchJson(memberUrl($workspace, $member), ['role' => 'guest']);

        $response->assertNotFound();
    });

    test('regular member cannot update roles (403)', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $target = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $workspace->addMember($owner, 'owner');
        $workspace->addMember($member, 'member');
        $workspace->addMember($target, 'member');

        Sanctum::actingAs($member);

        $response = $this->patchJson(memberUrl($workspace, $target), ['role' => 'guest']);

        $response->assertForbidden();
    });
});

// ─────────────────────────────────────────────
// AC 5/6 – Workspace Isolation
// ─────────────────────────────────────────────

describe('Workspace Members - Isolation (AC 5, 6)', function () {
    test('cannot list members of a workspace you do not belong to', function () {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');

        Sanctum::actingAs($outsider);

        $response = $this->getJson(memberUrl($workspace));

        $response->assertForbidden();
    });

    test('members from other workspaces are not visible in current workspace list', function () {
        $owner1 = User::factory()->create();
        $owner2 = User::factory()->create();
        $workspace1 = Workspace::factory()->create(['owner_id' => $owner1->id]);
        $workspace2 = Workspace::factory()->create(['owner_id' => $owner2->id]);

        $workspace1->addMember($owner1, 'owner');
        $workspace2->addMember($owner2, 'owner');

        Sanctum::actingAs($owner1);

        $response = $this->getJson(memberUrl($workspace1));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['user_id' => $owner1->id]);
    });
});

// ─────────────────────────────────────────────
// Security & Edge Cases
// ─────────────────────────────────────────────

describe('Workspace Members - Security', function () {
    test('unauthenticated user cannot remove a member', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');
        $workspace->addMember($member, 'member');

        $response = $this->deleteJson(memberUrl($workspace, $member));

        $response->assertUnauthorized();
    });

    test('unauthenticated user cannot update member role', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');
        $workspace->addMember($member, 'member');

        $response = $this->patchJson(memberUrl($workspace, $member), ['role' => 'guest']);

        $response->assertUnauthorized();
    });

    test('owner can remove another owner if not last owner', function () {
        $owner = User::factory()->create();
        $secondOwner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');
        $workspace->addMember($secondOwner, 'owner');

        Sanctum::actingAs($owner);

        $response = $this->deleteJson(memberUrl($workspace, $secondOwner));

        $response->assertNoContent();
    });

    test('removed member loses access to workspace routes', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');
        $workspace->addMember($member, 'member');

        Sanctum::actingAs($owner);
        $this->deleteJson(memberUrl($workspace, $member))->assertNoContent();

        Sanctum::actingAs($member);
        $this->getJson(memberUrl($workspace))->assertForbidden();
    });

    test('removed member can rejoin workspace after being re-invited', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');
        $workspace->addMember($member, 'member');

        Sanctum::actingAs($owner);
        $this->deleteJson(memberUrl($workspace, $member))->assertNoContent();

        // Re-add the member (simulates accepting a new invitation)
        $workspace->addMember($member, 'guest');

        $response = $this->getJson(memberUrl($workspace));
        $response->assertOk()
            ->assertJsonFragment(['user_id' => $member->id, 'role' => 'guest']);
    });
});
