<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Workspace Invitation - Model & Relationships', function () {
    test('workspace invitation belongs to workspace and inviter', function () {
        $inviter = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $inviter->id]);

        $invitation = WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => 'invitee@example.com',
            'role' => 'member',
            'token' => hash('sha256', 'raw-token'),
            'status' => 'pending',
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);

        expect($invitation->workspace)->toBeInstanceOf(Workspace::class)
            ->and($invitation->workspace->id)->toBe($workspace->id)
            ->and($invitation->inviter)->toBeInstanceOf(User::class)
            ->and($invitation->inviter->id)->toBe($inviter->id);
    });

    test('workspace invitation casts dates to datetime', function () {
        $inviter = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $inviter->id]);

        $invitation = WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => 'invitee@example.com',
            'role' => 'member',
            'token' => hash('sha256', 'raw-token'),
            'status' => 'pending',
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
            'accepted_at' => now(),
        ]);

        expect($invitation->expires_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
            ->and($invitation->accepted_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    });
});

describe('Workspace Invitation - Status Helpers', function () {
    test('status helpers return correct values', function () {
        $inviter = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $inviter->id]);

        $pending = WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => 'pending@example.com',
            'role' => 'member',
            'token' => hash('sha256', 'raw-token-1'),
            'status' => 'pending',
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);

        $accepted = WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => 'accepted@example.com',
            'role' => 'member',
            'token' => hash('sha256', 'raw-token-2'),
            'status' => 'accepted',
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
            'accepted_at' => now(),
        ]);

        $declined = WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => 'declined@example.com',
            'role' => 'member',
            'token' => hash('sha256', 'raw-token-3'),
            'status' => 'declined',
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(7),
        ]);

        $expired = WorkspaceInvitation::create([
            'workspace_id' => $workspace->id,
            'email' => 'expired@example.com',
            'role' => 'member',
            'token' => hash('sha256', 'raw-token-4'),
            'status' => 'pending',
            'invited_by' => $inviter->id,
            'expires_at' => now()->subDay(),
        ]);

        expect($pending->isPending())->toBeTrue()
            ->and($pending->isAccepted())->toBeFalse()
            ->and($pending->isDeclined())->toBeFalse()
            ->and($pending->isExpired())->toBeFalse();

        expect($accepted->isAccepted())->toBeTrue()
            ->and($accepted->isPending())->toBeFalse()
            ->and($accepted->isDeclined())->toBeFalse()
            ->and($accepted->isExpired())->toBeFalse();

        expect($declined->isDeclined())->toBeTrue()
            ->and($declined->isPending())->toBeFalse()
            ->and($declined->isAccepted())->toBeFalse();

        expect($expired->isExpired())->toBeTrue();
    });
});
