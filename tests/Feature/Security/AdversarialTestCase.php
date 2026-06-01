<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Adversarial test helpers for cross-tenant security testing.
 *
 * Used as a Pest trait via `pest()->extend()->in('Feature/Security')` in Pest.php,
 * or directly via `uses(AdversarialTestCase::class)` in any Pest test file.
 */
trait AdversarialTestCase
{
    use RefreshDatabase;

    protected User $attackerUser;

    protected Workspace $attackerWorkspace;

    protected User $victimUser;

    protected Workspace $victimWorkspace;

    protected function setUpAdversarialScenario(): void
    {
        $this->attackerUser = User::factory()->create();
        $this->attackerWorkspace = Workspace::factory()->create();
        $this->attackerWorkspace->addMember($this->attackerUser, 'admin');

        $this->victimUser = User::factory()->create();
        $this->victimWorkspace = Workspace::factory()->create();
        $this->victimWorkspace->addMember($this->victimUser, 'admin');
    }

    protected function actAsAttacker(): static
    {
        Sanctum::actingAs($this->attackerUser);

        return $this->withHeaders(['X-Workspace-ID' => $this->attackerWorkspace->id]);
    }

    protected function attemptCrossWorkspaceAccess(
        string $method,
        string $uri,
        array $data = []
    ): TestResponse {
        return match (strtoupper($method)) {
            'GET' => $this->actAsAttacker()->getJson($uri),
            'POST' => $this->actAsAttacker()->postJson($uri, $data),
            'PATCH',
            'PUT' => $this->actAsAttacker()->putJson($uri, $data),
            'DELETE' => $this->actAsAttacker()->deleteJson($uri),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };
    }

    /**
     * Assert 404 — for tenant-scoped resources hidden by WorkspaceScope.
     * Use for: /api/v1/projects/{id}, /api/v1/projects/{id}/restore
     */
    protected function assertResourceDenied(TestResponse $response): void
    {
        $response->assertNotFound();
    }

    /**
     * Assert 403 — for workspace-level routes blocked by SetCurrentWorkspace middleware.
     * Use for: /api/v1/workspaces/{workspace}/members, /api/v1/workspaces/{workspace}/invitations
     */
    protected function assertWorkspaceDenied(TestResponse $response): void
    {
        $response->assertForbidden();
    }

    /**
     * Generate and run standard adversarial isolation tests for any resource.
     * Verifies READ, UPDATE, DELETE isolation + CREATE workspace_id injection.
     *
     * @param  class-string  $resourceClass  Eloquent model class (e.g. Project::class)
     * @param  string  $baseRoute  API route prefix without trailing slash (e.g. '/api/v1/projects')
     * @param  array  $createData  Minimal valid data to create the resource
     */
    protected function testResourceIsolation(
        string $resourceClass,
        string $baseRoute,
        array $createData
    ): void {
        // Create a victim resource directly (bypasses current workspace context)
        $victimResource = $resourceClass::factory()
            ->forWorkspace($this->victimWorkspace)
            ->create();
        $originalAttributes = $victimResource->getAttributes();

        // READ isolation — attacker cannot see victim's resource
        $this->attemptCrossWorkspaceAccess('GET', "{$baseRoute}/{$victimResource->id}")
            ->assertNotFound();

        // UPDATE isolation — attacker cannot modify victim's resource
        $this->attemptCrossWorkspaceAccess('PATCH', "{$baseRoute}/{$victimResource->id}", $createData)
            ->assertNotFound();

        // Verify resource unchanged — use acrossWorkspaces() since current_workspace is now attacker's
        $unchanged = $resourceClass::acrossWorkspaces()->find($victimResource->id);
        expect($unchanged)->not->toBeNull();
        expect($unchanged->getAttributes())->toMatchArray($originalAttributes);

        // DELETE isolation — attacker cannot delete victim's resource
        $this->attemptCrossWorkspaceAccess('DELETE', "{$baseRoute}/{$victimResource->id}")
            ->assertNotFound();

        // Verify resource still exists — use acrossWorkspaces() since current_workspace is still attacker's
        $resourceQuery = $resourceClass::acrossWorkspaces();
        if ($this->usesSoftDeletes($resourceClass)) {
            $resourceQuery->withTrashed();
        }
        expect($resourceQuery->find($victimResource->id))->not->toBeNull();

        // CREATE injection — attacker tries to inject victim's workspace_id
        $response = $this->actAsAttacker()->postJson($baseRoute, array_merge($createData, [
            'workspace_id' => $this->victimWorkspace->id, // injection attempt
        ]));

        $response->assertSuccessful();

        $created = $resourceClass::acrossWorkspaces()->find($response->json('data.id'));
        expect($created)->not->toBeNull();
        expect($created?->workspace_id)->toBe($this->attackerWorkspace->id);
        expect($created?->workspace_id)->not->toBe($this->victimWorkspace->id);
    }

    /**
     * @param  class-string  $resourceClass
     */
    protected function usesSoftDeletes(string $resourceClass): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($resourceClass), true);
    }
}
