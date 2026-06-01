<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-02-18 10:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('unauthenticated users are redirected to login with token preserved', function () {
    $inviter = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $inviter->id]);
    $rawToken = 'raw-invite-token';

    WorkspaceInvitation::create([
        'workspace_id' => $workspace->id,
        'email' => 'invitee@example.com',
        'role' => 'member',
        'token' => hash('sha256', $rawToken),
        'status' => 'pending',
        'invited_by' => $inviter->id,
        'expires_at' => now()->addDays(7),
    ]);

    $response = $this->get("/invitations/accept/{$rawToken}");

    $response->assertRedirect("/login?invitation={$rawToken}");
});

test('authenticated users can accept a valid invitation', function () {
    $inviter = User::factory()->create();
    $invitee = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $inviter->id]);
    $rawToken = 'raw-invite-token';

    $invitation = WorkspaceInvitation::create([
        'workspace_id' => $workspace->id,
        'email' => $invitee->email,
        'role' => 'admin',
        'token' => hash('sha256', $rawToken),
        'status' => 'pending',
        'invited_by' => $inviter->id,
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($invitee);

    $response = $this->get("/invitations/accept/{$rawToken}");

    $response->assertRedirect('/dashboard');

    $this->assertDatabaseHas('workspace_members', [
        'workspace_id' => $workspace->id,
        'user_id' => $invitee->id,
        'role' => 'admin',
    ]);

    $invitation->refresh();
    expect($invitation->status)->toBe('accepted')
        ->and($invitation->accepted_at)->not->toBeNull();
});

test('expired invitations return an error message', function () {
    $inviter = User::factory()->create();
    $invitee = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $inviter->id]);
    $rawToken = 'raw-invite-token';

    WorkspaceInvitation::create([
        'workspace_id' => $workspace->id,
        'email' => $invitee->email,
        'role' => 'member',
        'token' => hash('sha256', $rawToken),
        'status' => 'pending',
        'invited_by' => $inviter->id,
        'expires_at' => now()->subDay(),
    ]);

    $this->actingAs($invitee);

    $response = $this->get("/invitations/accept/{$rawToken}");

    $response->assertStatus(410)
        ->assertSeeText('This invitation has expired');
});

test('used invitations return an error message', function () {
    $inviter = User::factory()->create();
    $invitee = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $inviter->id]);
    $rawToken = 'raw-invite-token';

    WorkspaceInvitation::create([
        'workspace_id' => $workspace->id,
        'email' => $invitee->email,
        'role' => 'member',
        'token' => hash('sha256', $rawToken),
        'status' => 'accepted',
        'invited_by' => $inviter->id,
        'expires_at' => now()->addDays(7),
        'accepted_at' => now(),
    ]);

    $this->actingAs($invitee);

    $response = $this->get("/invitations/accept/{$rawToken}");

    $response->assertStatus(409)
        ->assertSeeText('This invitation has already been used');
});
