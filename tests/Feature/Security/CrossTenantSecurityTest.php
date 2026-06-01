<?php

use App\Models\Project;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->setUpAdversarialScenario();
});

// ---------------------------------------------------------------------------
// PART 2 — Cross-Tenant Read Attacks
// ---------------------------------------------------------------------------
describe('Cross-Tenant Read Attacks', function () {
    test('attacker cannot view victim project by ID', function () {
        $victimProject = Project::factory()->forWorkspace($this->victimWorkspace)->create(['name' => 'Secret Project']);

        $response = $this->attemptCrossWorkspaceAccess('GET', "/api/v1/projects/{$victimProject->id}");

        $this->assertResourceDenied($response);

        $responseBody = json_encode($response->json() ?? [], JSON_THROW_ON_ERROR);
        expect($responseBody)->not->toContain('Secret Project');
        expect($response->json('data'))->toBeNull();
    });

    test('attacker cannot see victim projects in list', function () {
        Project::factory()->count(5)->forWorkspace($this->victimWorkspace)->create();
        Project::factory()->count(3)->forWorkspace($this->attackerWorkspace)->create();

        $response = $this->actAsAttacker()->getJson('/api/v1/projects');

        $response->assertOk()
            ->assertJsonCount(3, 'data'); // only attacker's 3 projects visible

        foreach ($response->json('data') as $project) {
            expect($project['workspace_id'])->toBe($this->attackerWorkspace->id);
        }
    });
});

// ---------------------------------------------------------------------------
// PART 3 — Cross-Tenant Write Attacks
// ---------------------------------------------------------------------------
describe('Cross-Tenant Write Attacks', function () {
    test('attacker cannot update victim project', function () {
        $victimProject = Project::factory()->forWorkspace($this->victimWorkspace)->create(['name' => 'Original Name']);

        $this->assertResourceDenied(
            $this->attemptCrossWorkspaceAccess('PATCH', "/api/v1/projects/{$victimProject->id}", ['name' => 'Hacked Name'])
        );

        // Use acrossWorkspaces() since current_workspace is now attacker's after the PATCH request
        expect(Project::acrossWorkspaces()->find($victimProject->id)?->name)->toBe('Original Name');
    });

    test('attacker cannot delete victim project', function () {
        $victimProject = Project::factory()->forWorkspace($this->victimWorkspace)->create();

        $this->assertResourceDenied(
            $this->attemptCrossWorkspaceAccess('DELETE', "/api/v1/projects/{$victimProject->id}")
        );

        // Use acrossWorkspaces() since current_workspace is still attacker's after the DELETE request
        expect(Project::acrossWorkspaces()->withTrashed()->find($victimProject->id))->not->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// PART 4 — Cross-Tenant Creation Attacks (workspace_id injection)
// ---------------------------------------------------------------------------
describe('Cross-Tenant Creation Attacks', function () {
    test('attacker cannot inject victim workspace_id on project creation', function () {
        $response = $this->actAsAttacker()->postJson('/api/v1/projects', [
            'workspace_id' => $this->victimWorkspace->id, // injection attempt
            'name' => 'Malicious Project',
            'status' => 'active',
        ]);

        $response->assertCreated();

        $created = Project::acrossWorkspaces()->find($response->json('data.id'));

        expect($created?->workspace_id)->toBe($this->attackerWorkspace->id)
            ->and($created?->workspace_id)->not->toBe($this->victimWorkspace->id);
    });
});

// ---------------------------------------------------------------------------
// PART 5 — Workspace Switching Attack
// ---------------------------------------------------------------------------
describe('Workspace Switching Attack', function () {
    test('attacker cannot impersonate victim workspace via X-Workspace-ID header', function () {
        Sanctum::actingAs($this->attackerUser);

        $this->withHeaders(['X-Workspace-ID' => $this->victimWorkspace->id])
            ->getJson('/api/v1/projects')
            ->assertForbidden();
    });
});

// ---------------------------------------------------------------------------
// PART 6 — URL Parameter Tampering
// ---------------------------------------------------------------------------
describe('URL Parameter Tampering', function () {
    test('attacker cannot access victim workspace members via route parameter', function () {
        Sanctum::actingAs($this->attackerUser);

        $this->assertWorkspaceDenied(
            $this->withHeaders(['X-Workspace-ID' => $this->attackerWorkspace->id])
                ->getJson("/api/v1/workspaces/{$this->victimWorkspace->id}/members")
        );
    });

    test('attacker cannot post invitations to victim workspace', function () {
        Sanctum::actingAs($this->attackerUser);

        $this->assertWorkspaceDenied(
            $this->withHeaders(['X-Workspace-ID' => $this->attackerWorkspace->id])
                ->postJson("/api/v1/workspaces/{$this->victimWorkspace->id}/invitations", [
                    'email' => 'spy@example.com',
                    'role' => 'member',
                ])
        );
    });

    test('attacker cannot access victim project even with correct attacker header', function () {
        $victimProject = Project::factory()->forWorkspace($this->victimWorkspace)->create();

        // Attacker uses their OWN workspace header — but victim project ID
        $this->assertResourceDenied(
            $this->attemptCrossWorkspaceAccess('GET', "/api/v1/projects/{$victimProject->id}")
        );
    });
});

// ---------------------------------------------------------------------------
// PART 7 — Automated Test Generator
// ---------------------------------------------------------------------------
describe('Automated Adversarial Test Generator', function () {
    test('testResourceIsolation passes for Projects', function () {
        $this->testResourceIsolation(
            Project::class,
            '/api/v1/projects',
            ['name' => 'Attack Project', 'status' => 'active']
        );
    });
});
