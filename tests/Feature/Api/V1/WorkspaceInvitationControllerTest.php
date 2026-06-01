<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-02-18 10:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('Workspace Invitation - Create', function () {
    test('owner can invite a member and email is queued', function () {
        Notification::fake();

        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'invitee@example.com',
            'role' => 'member',
        ]);

        $response->assertCreated()
            ->assertJsonFragment([
                'email' => 'invitee@example.com',
                'role' => 'member',
                'status' => 'pending',
            ]);

        $this->assertDatabaseHas('workspace_invitations', [
            'workspace_id' => $workspace->id,
            'email' => 'invitee@example.com',
            'role' => 'member',
            'status' => 'pending',
            'invited_by' => $owner->id,
        ]);

        $invitation = WorkspaceInvitation::first();
        expect($invitation->expires_at->toDateTimeString())
            ->toBe(Carbon::now()->addDays(7)->toDateTimeString());

        Notification::assertSentOnDemand(WorkspaceInvitationNotification::class);
    });

    test('admin can invite a member', function () {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $workspace->addMember($admin, 'admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'invitee@example.com',
            'role' => 'member',
        ]);

        $response->assertCreated();
    });

    test('non-owner non-admin cannot invite', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $workspace->addMember($member, 'member');
        Sanctum::actingAs($member);

        $response = $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'invitee@example.com',
            'role' => 'member',
        ]);

        $response->assertForbidden();
    });

    test('validates email and role', function () {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'not-an-email',
            'role' => 'invalid-role',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'role']);
    });

    test('returns 422 when email already belongs to a workspace member', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create(['email' => 'member@example.com']);
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $workspace->addMember($member, 'member');
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'member@example.com',
            'role' => 'member',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    test('prevents duplicate pending invitations', function () {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => 'invitee@example.com',
            'role' => 'member',
            'token' => hash('sha256', 'raw-token'),
            'status' => 'pending',
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'invitee@example.com',
            'role' => 'member',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    test('cannot invite users for a workspace without access', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'invitee@example.com',
            'role' => 'member',
        ]);

        $response->assertForbidden();
    });
});

describe('Workspace Invitation - Management', function () {
    test('owner can list pending invitations', function () {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => 'pending@example.com',
            'role' => 'member',
            'token' => hash('sha256', 'raw-token-1'),
            'status' => 'pending',
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        WorkspaceInvitation::create([
            'workspace_id' => $otherWorkspace->id,
            'email' => 'other@example.com',
            'role' => 'member',
            'token' => hash('sha256', 'raw-token-3'),
            'status' => 'pending',
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => 'accepted@example.com',
            'role' => 'member',
            'token' => hash('sha256', 'raw-token-2'),
            'status' => 'accepted',
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/v1/workspaces/{$workspace->id}/invitations");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['email' => 'pending@example.com']);
    });

    test('admin cannot list pending invitations', function () {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $workspace->addMember($admin, 'admin');
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/workspaces/{$workspace->id}/invitations");

        $response->assertForbidden();
    });

    test('owner can cancel a pending invitation', function () {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $invitation = WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => 'pending@example.com',
            'role' => 'member',
            'token' => hash('sha256', 'raw-token-1'),
            'status' => 'pending',
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        Sanctum::actingAs($owner);

        $response = $this->deleteJson("/api/v1/workspaces/{$workspace->id}/invitations/{$invitation->id}");

        $response->assertNoContent();

        $invitation->refresh();
        expect($invitation->status)->toBe('canceled');
    });

    test('cannot cancel an invitation from another workspace', function () {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $otherOwner->id]);

        $invitation = WorkspaceInvitation::create([
            'workspace_id' => $otherWorkspace->id,
            'email' => 'pending@example.com',
            'role' => 'member',
            'token' => hash('sha256', 'raw-token-1'),
            'status' => 'pending',
            'invited_by' => $otherOwner->id,
            'expires_at' => now()->addDays(7),
        ]);

        Sanctum::actingAs($owner);

        $response = $this->deleteJson("/api/v1/workspaces/{$workspace->id}/invitations/{$invitation->id}");

        $response->assertNotFound();
    });
});

describe('Workspace Invitation - Accept', function () {
    test('authenticated user can accept a pending invitation', function () {
        $owner = User::factory()->create();
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $rawToken = 'raw-accept-token';

        $invitation = WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => 'invitee@example.com',
            'role' => 'member',
            'token' => hash('sha256', $rawToken),
            'status' => 'pending',
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        Sanctum::actingAs($invitee);

        $response = $this->postJson("/api/v1/invitations/{$rawToken}/accept");

        $response->assertOk()
            ->assertJsonFragment(['message' => 'Invitation accepted.']);

        $invitation->refresh();
        expect($invitation->status)->toBe('accepted')
            ->and($invitation->accepted_at)->not->toBeNull();

        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $invitee->id,
            'role' => 'member',
        ]);
    });

    test('accept adds user as member with correct role', function () {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $rawToken = 'raw-admin-token';

        WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => $invitee->email,
            'role' => 'admin',
            'token' => hash('sha256', $rawToken),
            'status' => 'pending',
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        Sanctum::actingAs($invitee);

        $this->postJson("/api/v1/invitations/{$rawToken}/accept")->assertOk();

        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $invitee->id,
            'role' => 'admin',
        ]);
    });

    test('cannot accept invitation with a different authenticated user', function () {
        $owner = User::factory()->create();
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);
        $otherUser = User::factory()->create(['email' => 'other@example.com']);
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $rawToken = 'raw-wrong-user-token';

        $invitation = WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => $invitee->email,
            'role' => 'member',
            'token' => hash('sha256', $rawToken),
            'status' => 'pending',
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        Sanctum::actingAs($otherUser);

        $response = $this->postJson("/api/v1/invitations/{$rawToken}/accept");

        $response->assertForbidden()
            ->assertJsonFragment(['message' => 'This action is unauthorized.']);

        $invitation->refresh();
        expect($invitation->status)->toBe('pending');

        $this->assertDatabaseMissing('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $otherUser->id,
        ]);
    });

    test('accept does not duplicate membership if already a member', function () {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($invitee, 'member');
        $rawToken = 'raw-dup-token';

        WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => $invitee->email,
            'role' => 'member',
            'token' => hash('sha256', $rawToken),
            'status' => 'pending',
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        Sanctum::actingAs($invitee);

        $this->postJson("/api/v1/invitations/{$rawToken}/accept")->assertOk();

        expect(WorkspaceMember::where('workspace_id', $workspace->id)
            ->where('user_id', $invitee->id)
            ->count()
        )->toBe(1);
    });

    test('cannot accept expired invitation', function () {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $rawToken = 'raw-expired-token';

        WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => $invitee->email,
            'role' => 'member',
            'token' => hash('sha256', $rawToken),
            'status' => 'pending',
            'invited_by' => $owner->id,
            'expires_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($invitee);

        $response = $this->postJson("/api/v1/invitations/{$rawToken}/accept");
        $response->assertStatus(410);
    });

    test('cannot accept already accepted invitation', function () {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $rawToken = 'raw-used-token';

        WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => $invitee->email,
            'role' => 'member',
            'token' => hash('sha256', $rawToken),
            'status' => 'accepted',
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($invitee);

        $response = $this->postJson("/api/v1/invitations/{$rawToken}/accept");
        $response->assertConflict();
    });

    test('cannot accept non-existent invitation', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/invitations/invalid-token/accept');
        $response->assertNotFound();
    });

    test('unauthenticated user cannot accept', function () {
        $response = $this->postJson('/api/v1/invitations/some-token/accept');
        $response->assertUnauthorized();
    });

    test('store response includes accept_token', function () {
        Notification::fake();

        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'newuser@example.com',
            'role' => 'member',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['accept_token']]);

        expect($response->json('data.accept_token'))->toBeString()->not->toBeEmpty();
    });
});

describe('Workspace Invitation - Decline', function () {
    test('can decline a pending invitation', function () {
        $inviter = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $inviter->id]);
        $rawToken = 'raw-invite-token';

        $invitation = WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => 'invitee@example.com',
            'role' => 'member',
            'token' => hash('sha256', $rawToken),
            'status' => 'pending',
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->postJson("/api/v1/invitations/{$rawToken}/decline");

        $response->assertOk()
            ->assertJsonFragment(['message' => 'Invitation declined.']);

        $invitation->refresh();
        expect($invitation->status)->toBe('declined');
    });

    test('cannot decline an expired invitation', function () {
        $inviter = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $inviter->id]);
        $rawToken = 'expired-invite-token';

        WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => 'invitee@example.com',
            'role' => 'member',
            'token' => hash('sha256', $rawToken),
            'status' => 'pending',
            'invited_by' => $inviter->id,
            'expires_at' => now()->subDay(),
        ]);

        $this->postJson("/api/v1/invitations/{$rawToken}/decline")
            ->assertStatus(410);
    });

    test('cannot decline an already accepted invitation', function () {
        $inviter = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $inviter->id]);
        $rawToken = 'accepted-invite-token';

        WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => 'invitee@example.com',
            'role' => 'member',
            'token' => hash('sha256', $rawToken),
            'status' => WorkspaceInvitation::STATUS_ACCEPTED,
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
            'accepted_at' => now(),
        ]);

        $this->postJson("/api/v1/invitations/{$rawToken}/decline")
            ->assertConflict();
    });

    test('cannot decline an already declined invitation', function () {
        $inviter = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $inviter->id]);
        $rawToken = 'declined-invite-token';

        WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => 'invitee@example.com',
            'role' => 'member',
            'token' => hash('sha256', $rawToken),
            'status' => WorkspaceInvitation::STATUS_DECLINED,
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);

        $this->postJson("/api/v1/invitations/{$rawToken}/decline")
            ->assertConflict();
    });

    test('returns 404 for invalid decline token', function () {
        $this->postJson('/api/v1/invitations/totally-invalid-token/decline')
            ->assertNotFound();
    });
});

describe('Workspace Invitation - Admin Role Restriction', function () {
    test('admin cannot invite with owner role', function () {
        Notification::fake();

        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($admin, 'admin');

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'newuser@example.com',
            'role' => 'owner',
        ])->assertUnprocessable();
    });

    test('admin cannot invite with admin role', function () {
        Notification::fake();

        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($admin, 'admin');

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'newuser@example.com',
            'role' => 'admin',
        ])->assertUnprocessable();
    });

    test('admin can invite with member role', function () {
        Notification::fake();

        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($admin, 'admin');

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'newmember@example.com',
            'role' => 'member',
        ])->assertCreated();
    });

    test('owner can invite with owner role', function () {
        Notification::fake();

        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'newowner@example.com',
            'role' => 'owner',
        ])->assertCreated();
    });
});
