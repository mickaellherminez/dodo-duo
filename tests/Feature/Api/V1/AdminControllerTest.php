<?php

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

describe('AdminController dashboard', function () {
    test('super-admin gets aggregated cross-workspace stats and audit log is created', function () {
        $superAdmin = User::factory()->superAdmin()->create();
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();

        Project::factory()->forWorkspace($workspaceA)->active()->create(['name' => 'A1']);
        Project::factory()->forWorkspace($workspaceA)->archived()->create(['name' => 'A2']);
        Project::factory()->forWorkspace($workspaceB)->active()->create(['name' => 'B1']);
        Project::factory()->forWorkspace($workspaceB)->completed()->create(['name' => 'B2']);

        // Bind a tenant context on purpose to prove the controller uses the explicit
        // cross-workspace escape hatch rather than relying on null-context behavior.
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspaceA));

        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.scope.workspace_scope_bypassed', true)
            ->assertJsonPath('data.scope.helper', 'forAllWorkspaces')
            ->assertJsonPath('data.counts.workspaces', 2)
            ->assertJsonPath('data.counts.projects.total', 4)
            ->assertJsonPath('data.counts.projects.by_status.active', 2)
            ->assertJsonPath('data.counts.projects.by_status.archived', 1)
            ->assertJsonPath('data.counts.projects.by_status.completed', 1);

        $log = AuditLog::query()
            ->where('event', AuditEvent::ADMIN_DASHBOARD_VIEWED)
            ->latest()
            ->first();

        expect($log)->not->toBeNull()
            // This test binds CurrentWorkspace on purpose to prove the explicit bypass.
            // AuditService captures that bound context, which is acceptable traceability.
            ->and($log?->workspace_id)->toBe($workspaceA->id)
            ->and($log?->user_id)->toBe($superAdmin->id)
            ->and($log?->new_values['scope']['workspace_scope_bypassed'] ?? null)->toBeTrue()
            ->and($log?->new_values['scope']['helper'] ?? null)->toBe('forAllWorkspaces')
            ->and($log?->new_values['counts']['projects']['total'] ?? null)->toBe(4);
    });

    test('non-super-admin gets 403 forbidden', function () {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/dashboard')
            ->assertForbidden();

        expect(AuditLog::where('event', AuditEvent::ADMIN_DASHBOARD_VIEWED)->exists())->toBeFalse();
    });

    test('unauthenticated request gets 401', function () {
        $this->getJson('/api/v1/admin/dashboard')
            ->assertUnauthorized();
    });
});
