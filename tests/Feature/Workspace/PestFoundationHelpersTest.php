<?php

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Pest foundation helpers and datasets', function () {
    test('createUserWithWorkspace helper provisions owner membership and workspace headers', function () {
        ['user' => $user, 'workspace' => $workspace] = $this->createUserWithWorkspace();

        expect($workspace->owner_id)->toBe($user->id)
            ->and($workspace->getMemberRole($user))->toBe('owner')
            ->and(['workspace_id' => $workspace->id])->toHaveWorkspaceId($workspace->id)
            ->and(workspace_header($workspace))->toBe(['X-Workspace-ID' => $workspace->id]);
    });

    test('createUserWithRole helper attaches requested workspace role', function (string $role) {
        ['user' => $user, 'workspace' => $workspace] = $this->createUserWithRole($role);

        expect($workspace->getMemberRole($user))->toBeWorkspaceRole()
            ->and($workspace->getMemberRole($user))->toBe($role);
    })->with('workspace_roles');

    test('validation helper and workspace auth helper compose cleanly for project create endpoint', function () {
        ['user' => $user, 'workspace' => $workspace] = $this->createUserWithRole('admin');

        $response = $this->actingAsWithWorkspace($user, $workspace)
            ->withWorkspaceHeaders($workspace)
            ->postJson('/api/v1/projects', [
                'status' => 'active',
            ]);

        $this->assertHasValidationErrors($response, ['name']);
    });

    test('project status dataset supports example allowed statuses', function (string $status) {
        ['workspace' => $workspace] = $this->createUserWithRole('admin');

        $project = Project::factory()
            ->forWorkspace($workspace)
            ->create([
                'status' => $status,
            ]);

        expect($project->status)->toBe($status)
            ->and($project->toArray())->toHaveWorkspaceId($workspace->id);
    })->with('project_statuses');
});
