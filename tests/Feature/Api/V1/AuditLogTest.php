<?php

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\AuditService;
use App\Services\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function auditSignIn(User $user): void
{
    Sanctum::actingAs($user);
}

function auditWsHeader(Workspace $workspace): array
{
    return ['X-Workspace-ID' => $workspace->id];
}

/**
 * Create an AuditLog directly (bypasses observers, no workspace context needed).
 */
function makeAuditLog(array $overrides = []): AuditLog
{
    return AuditLog::create(array_merge([
        'workspace_id' => null,
        'user_id' => null,
        'event' => 'test.event',
        'auditable_type' => WorkspaceMember::class,
        'auditable_id' => 1,
        'old_values' => null,
        'new_values' => null,
        'ip_address' => null,
        'user_agent' => null,
    ], $overrides));
}

/**
 * Create a WorkspaceMember row without triggering the observer.
 */
function attachMember(Workspace $workspace, User $user, string $role = 'member'): WorkspaceMember
{
    $workspace->members()->attach($user->id, ['role' => $role, 'joined_at' => now()]);

    return WorkspaceMember::where('workspace_id', $workspace->id)
        ->where('user_id', $user->id)
        ->first();
}

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->workspace = Workspace::factory()->create();
    // attach() bypasses the observer — no audit log created during setup
    $this->workspace->members()->attach($this->admin->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
});

// ---------------------------------------------------------------------------
// AuditService
// ---------------------------------------------------------------------------

describe('AuditService', function () {
    test('creates audit log with correct event and auditable fields', function () {
        $user = User::factory()->create();
        $member = attachMember($this->workspace, $user, 'member');

        Sanctum::actingAs($this->admin);

        $log = AuditService::log(AuditEvent::MEMBER_ADDED, $member, null, ['role' => 'member']);

        expect($log)->toBeInstanceOf(AuditLog::class);
        expect($log->event)->toBe(AuditEvent::MEMBER_ADDED);
        expect($log->auditable_type)->toBe($member->getMorphClass());
        expect($log->auditable_id)->toBe($member->getKey());
        expect($log->user_id)->toBe($this->admin->id);
        expect($log->new_values)->toBe(['role' => 'member']);
        expect($log->old_values)->toBeNull();
    });

    test('captures null workspace_id when no workspace context exists', function () {
        $user = User::factory()->create();
        $member = attachMember($this->workspace, $user, 'member');

        $log = AuditService::log(AuditEvent::MEMBER_ADDED, $member);

        // current_workspace_id() returns null outside HTTP middleware
        expect($log->workspace_id)->toBeNull();
    });

    test('captures workspace_id when workspace context is bound', function () {
        $user = User::factory()->create();
        $member = attachMember($this->workspace, $user, 'member');

        // Manually bind workspace context (as SetCurrentWorkspace middleware would)
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($this->workspace));
        Sanctum::actingAs($this->admin);

        $log = AuditService::log(AuditEvent::MEMBER_ADDED, $member, null, ['role' => 'member']);

        expect($log->workspace_id)->toBe($this->workspace->id);
        expect($log->user_id)->toBe($this->admin->id);
    });
});

// ---------------------------------------------------------------------------
// WorkspaceMemberObserver
// ---------------------------------------------------------------------------

describe('WorkspaceMemberObserver', function () {
    test('logs member.added when a new member is created', function () {
        $user = User::factory()->create();

        // addMember() calls WorkspaceMember::create() which fires the created observer
        $this->workspace->addMember($user, 'member');

        $log = AuditLog::where('event', AuditEvent::MEMBER_ADDED)->latest()->first();

        expect($log)->not->toBeNull();
        expect($log->new_values)->toBe(['role' => 'member']);
        expect($log->old_values)->toBeNull();
    });

    test('logs role.changed with old and new values when role is updated', function () {
        $user = User::factory()->create();
        $member = attachMember($this->workspace, $user, 'member');

        AuditLog::truncate();

        $member->update(['role' => 'admin']);

        $log = AuditLog::where('event', AuditEvent::ROLE_CHANGED)->first();

        expect($log)->not->toBeNull();
        expect($log->old_values)->toBe(['role' => 'member']);
        expect($log->new_values)->toBe(['role' => 'admin']);
    });

    test('does not log role.changed when role field is not dirty', function () {
        $user = User::factory()->create();
        $member = attachMember($this->workspace, $user, 'member');

        AuditLog::truncate();

        // Update a field other than role
        $member->update(['joined_at' => now()]);

        expect(AuditLog::where('event', AuditEvent::ROLE_CHANGED)->exists())->toBeFalse();
    });

    test('logs member.removed when member is soft deleted', function () {
        $user = User::factory()->create();
        $member = attachMember($this->workspace, $user, 'guest');

        AuditLog::truncate();

        $member->delete();

        $log = AuditLog::where('event', AuditEvent::MEMBER_REMOVED)->first();

        expect($log)->not->toBeNull();
        expect($log->old_values)->toBe(['role' => 'guest']);
        expect($log->new_values)->toBeNull();
    });

    test('logs member.added when a previously removed member is restored via addMember', function () {
        $user = User::factory()->create();
        $member = attachMember($this->workspace, $user, 'member');

        // Soft-delete at the DB level to bypass the observer (setup only)
        DB::table('workspace_members')
            ->where('id', $member->id)
            ->update(['deleted_at' => now()->toDateTimeString()]);

        AuditLog::truncate();

        // addMember() detects the trashed record, calls restore() → fires restored observer,
        // then calls update(['role' => 'admin']) → fires updated (ROLE_CHANGED) observer.
        // The MEMBER_ADDED log is created by restored(), using the role at restore time ('member').
        $this->workspace->addMember($user, 'admin');

        $log = AuditLog::where('event', AuditEvent::MEMBER_ADDED)->first();
        expect($log)->not->toBeNull();
        // restored fires before role update, so role captured is the original 'member'
        expect($log->new_values)->toBe(['role' => 'member']);
    });
});

// ---------------------------------------------------------------------------
// AuditLogController — access control
// ---------------------------------------------------------------------------

describe('AuditLogController - access control', function () {
    test('admin gets 200 with paginated audit logs', function () {
        makeAuditLog(['workspace_id' => $this->workspace->id, 'event' => AuditEvent::MEMBER_ADDED]);
        makeAuditLog(['workspace_id' => $this->workspace->id, 'event' => AuditEvent::MEMBER_REMOVED]);

        auditSignIn($this->admin);

        $this->withHeaders(auditWsHeader($this->workspace))
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/audit-logs")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'event', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'created_at'],
                ],
            ]);
    });

    test('owner gets 200 with audit logs', function () {
        $owner = User::factory()->create();
        $ownedWorkspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $ownedWorkspace->members()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
        makeAuditLog(['workspace_id' => $ownedWorkspace->id]);

        auditSignIn($owner);

        $this->withHeaders(auditWsHeader($ownedWorkspace))
            ->getJson("/api/v1/workspaces/{$ownedWorkspace->id}/audit-logs")
            ->assertOk();
    });

    test('regular member gets 403', function () {
        $member = User::factory()->create();
        $this->workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        auditSignIn($member);

        $this->withHeaders(auditWsHeader($this->workspace))
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/audit-logs")
            ->assertForbidden();
    });

    test('guest gets 403', function () {
        $guest = User::factory()->create();
        $this->workspace->members()->attach($guest->id, ['role' => 'guest', 'joined_at' => now()]);

        auditSignIn($guest);

        $this->withHeaders(auditWsHeader($this->workspace))
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/audit-logs")
            ->assertForbidden();
    });

    test('unauthenticated request gets 401', function () {
        $this->getJson("/api/v1/workspaces/{$this->workspace->id}/audit-logs")
            ->assertUnauthorized();
    });
});

// ---------------------------------------------------------------------------
// AuditLogController — filters
// ---------------------------------------------------------------------------

describe('AuditLogController - filters', function () {
    beforeEach(function () {
        makeAuditLog(['workspace_id' => $this->workspace->id, 'event' => AuditEvent::MEMBER_ADDED, 'user_id' => $this->admin->id]);
        makeAuditLog(['workspace_id' => $this->workspace->id, 'event' => AuditEvent::MEMBER_REMOVED, 'user_id' => null]);
        makeAuditLog(['workspace_id' => $this->workspace->id, 'event' => AuditEvent::ROLE_CHANGED, 'user_id' => $this->admin->id]);

        auditSignIn($this->admin);
    });

    test('filters by event', function () {
        $this->withHeaders(auditWsHeader($this->workspace))
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/audit-logs?event=member.added")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', AuditEvent::MEMBER_ADDED);
    });

    test('filters by user_id', function () {
        $this->withHeaders(auditWsHeader($this->workspace))
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/audit-logs?user_id={$this->admin->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    });

    test('filters by from date — excludes all when from is future', function () {
        $tomorrow = now()->addDay()->toDateString();

        $this->withHeaders(auditWsHeader($this->workspace))
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/audit-logs?from={$tomorrow}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    test('filters by to date — excludes all when to is past', function () {
        $yesterday = now()->subDay()->toDateString();

        $this->withHeaders(auditWsHeader($this->workspace))
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/audit-logs?to={$yesterday}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    test('returns all logs when no filter applied', function () {
        $this->withHeaders(auditWsHeader($this->workspace))
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/audit-logs")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });
});

// ---------------------------------------------------------------------------
// Workspace isolation
// ---------------------------------------------------------------------------

describe('Workspace isolation', function () {
    test('workspace A admin only sees own workspace logs on workspace A endpoint', function () {
        $otherWorkspace = Workspace::factory()->create();
        makeAuditLog(['workspace_id' => $otherWorkspace->id, 'event' => AuditEvent::MEMBER_ADDED]);
        makeAuditLog(['workspace_id' => $this->workspace->id, 'event' => AuditEvent::ROLE_CHANGED]);

        auditSignIn($this->admin);

        $this->withHeaders(auditWsHeader($this->workspace))
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/audit-logs")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', AuditEvent::ROLE_CHANGED);
    });

    test('admin of workspace A cannot read workspace B audit logs via URL with workspace A header', function () {
        // Workspace B has logs the attacker wants to read
        $workspaceB = Workspace::factory()->create();
        makeAuditLog(['workspace_id' => $workspaceB->id, 'event' => AuditEvent::MEMBER_ADDED]);

        auditSignIn($this->admin);

        // Attacker sends X-Workspace-ID=A but targets workspace B's endpoint
        $this->withHeaders(auditWsHeader($this->workspace))
            ->getJson("/api/v1/workspaces/{$workspaceB->id}/audit-logs")
            ->assertForbidden();
    });
});

// ---------------------------------------------------------------------------
// audit:prune command
// ---------------------------------------------------------------------------

describe('audit:prune command', function () {
    test('deletes logs older than default 90 days and keeps recent logs', function () {
        $old = makeAuditLog(['workspace_id' => $this->workspace->id]);
        $old->forceFill(['created_at' => now()->subDays(91)])->save();

        $recent = makeAuditLog(['workspace_id' => $this->workspace->id]);

        $this->artisan('audit:prune')
            ->assertExitCode(0)
            ->expectsOutputToContain('1');

        expect(AuditLog::count())->toBe(1);
        expect(AuditLog::first()->id)->toBe($recent->id);
    });

    test('respects --days option', function () {
        $old = makeAuditLog(['workspace_id' => $this->workspace->id]);
        $old->forceFill(['created_at' => now()->subDays(31)])->save();

        $recent = makeAuditLog(['workspace_id' => $this->workspace->id]);

        $this->artisan('audit:prune --days=30')
            ->assertExitCode(0);

        expect(AuditLog::count())->toBe(1);
        expect(AuditLog::first()->id)->toBe($recent->id);
    });

    test('preserves all logs when none are old enough to prune', function () {
        makeAuditLog(['workspace_id' => $this->workspace->id]);
        makeAuditLog(['workspace_id' => $this->workspace->id]);

        $this->artisan('audit:prune')
            ->assertExitCode(0);

        expect(AuditLog::count())->toBe(2);
    });

    test('rejects --days=0 and returns failure exit code', function () {
        makeAuditLog(['workspace_id' => $this->workspace->id]);

        $this->artisan('audit:prune --days=0')
            ->assertExitCode(1)
            ->expectsOutputToContain('at least 1');

        // Logs must be untouched
        expect(AuditLog::count())->toBe(1);
    });
});
