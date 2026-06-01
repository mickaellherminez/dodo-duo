<?php

namespace Tests;

use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrentWorkspace;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a user and an owned workspace with owner membership.
     *
     * Note: this helper uses Workspace::addMember(), so WorkspaceMember observers
     * (including audit logging) will run during test setup.
     *
     * @return array{user: User, workspace: Workspace}
     */
    protected function createUserWithWorkspace(array $userData = [], array $workspaceData = []): array
    {
        $user = User::factory()->create($userData);
        $workspace = Workspace::factory()->create(array_merge([
            'owner_id' => $user->id,
        ], $workspaceData));

        $workspace->addMember($user, 'owner');

        return [
            'user' => $user->fresh(),
            'workspace' => $workspace->fresh(),
        ];
    }

    /**
     * Create a user with a specific role in a workspace (creating one if needed).
     *
     * Note: this helper uses Workspace::addMember(), so WorkspaceMember observers
     * (including audit logging) may create audit records during test setup.
     *
     * @return array{user: User, workspace: Workspace}
     */
    protected function createUserWithRole(string $role, ?Workspace $workspace = null, array $userData = []): array
    {
        if ($workspace === null) {
            if ($role === 'owner') {
                return $this->createUserWithWorkspace($userData);
            }

            ['workspace' => $workspace] = $this->createUserWithWorkspace();
        }

        $user = User::factory()->create($userData);

        if ($role === 'owner' && $workspace->owner_id !== $user->id) {
            $workspace->update(['owner_id' => $user->id]);
        }

        $workspace->addMember($user, $role);

        return [
            'user' => $user->fresh(),
            'workspace' => $workspace->fresh(),
        ];
    }

    /**
     * Authenticate a user and bind the current workspace context for the test.
     *
     * @param  list<string>  $abilities
     */
    protected function actingAsWithWorkspace(User $user, Workspace $workspace, array $abilities = ['*']): static
    {
        Sanctum::actingAs($user, $abilities);
        $this->bindCurrentWorkspace($workspace);

        return $this;
    }

    /**
     * Bind the current workspace service to the container.
     */
    protected function bindCurrentWorkspace(Workspace $workspace): void
    {
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));
    }

    /**
     * Forget the bound current workspace (useful in null-context tests).
     */
    protected function forgetCurrentWorkspace(): void
    {
        app()->forgetInstance(CurrentWorkspace::class);
    }

    /**
     * Build workspace headers used by API routes protected by SetCurrentWorkspace.
     *
     * @return array<string, int>
     */
    protected function workspaceHeaders(Workspace|int $workspace): array
    {
        $workspaceId = $workspace instanceof Workspace
            ? $workspace->id
            : $workspace;

        return ['X-Workspace-ID' => $workspaceId];
    }

    /**
     * Convenience wrapper around withHeaders() for workspace-aware API requests.
     */
    protected function withWorkspaceHeaders(Workspace|int $workspace): static
    {
        return $this->withHeaders($this->workspaceHeaders($workspace));
    }

    /**
     * Assert a standard Laravel validation error response (422 + fields).
     *
     * @param  list<string>|string  $fields
     */
    protected function assertHasValidationErrors(TestResponse $response, array|string $fields): TestResponse
    {
        $expectedFields = is_array($fields) ? $fields : [$fields];

        $response->assertUnprocessable()
            ->assertJsonValidationErrors($expectedFields);

        return $response;
    }
}
