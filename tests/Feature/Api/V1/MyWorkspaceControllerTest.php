<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

describe('My Workspaces API Endpoint', function () {
    test('can list all workspaces user belongs to', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        // Create workspaces owned by user
        $workspace1 = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace2 = Workspace::factory()->create(['owner_id' => $user->id]);

        // Auto-add owner as member
        $workspace1->addMember($user, 'owner');
        $workspace2->addMember($user, 'owner');

        // Create workspace owned by other user
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $otherUser->id]);
        $otherWorkspace->addMember($otherUser, 'owner');

        $response = actingAs($user, 'sanctum')
            ->getJson('/api/v1/my/workspaces');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'domain',
                        'status',
                        'owner',
                        'member_count',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);

        // Verify it includes my workspaces
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        expect($ids)->toContain($workspace1->id, $workspace2->id);

        // Should NOT include other user's workspace
        expect($ids)->not->toContain($otherWorkspace->id);
    });

    test('includes workspace where user is member but not owner', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->addMember($owner, 'owner');
        $workspace->addMember($member, 'member');

        $response = actingAs($member, 'sanctum')
            ->getJson('/api/v1/my/workspaces');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $workspace->id);
    });

    test('returns empty array when user has no workspaces', function () {
        $user = User::factory()->create();

        $response = actingAs($user, 'sanctum')
            ->getJson('/api/v1/my/workspaces');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    });

    test('requires authentication', function () {
        $response = getJson('/api/v1/my/workspaces');

        $response->assertStatus(401);
    });

    test('includes owner and member count', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->addMember($user, 'owner');

        $member = User::factory()->create();
        $workspace->addMember($member, 'member');

        $response = actingAs($user, 'sanctum')
            ->getJson('/api/v1/my/workspaces');

        $response->assertOk()
            ->assertJsonPath('data.0.owner.id', $user->id)
            ->assertJsonPath('data.0.member_count', 2);
    });

    test('workspaces ordered by most recent membership', function () {
        $user = User::factory()->create();

        $workspace1 = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace1->addMember($user, 'owner');

        sleep(1); // Ensure different timestamps

        $workspace2 = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace2->addMember($user, 'owner');

        $response = actingAs($user, 'sanctum')
            ->getJson('/api/v1/my/workspaces');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $workspace2->id) // Most recent first
            ->assertJsonPath('data.1.id', $workspace1->id);
    });

    test('my workspaces includes correct structure', function () {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_id' => $user->id,
            'name' => 'Test Workspace',
            'slug' => 'test-workspace',
            'domain' => 'test.example.com',
            'status' => 'active',
        ]);
        $workspace->addMember($user, 'owner');

        $response = actingAs($user, 'sanctum')
            ->getJson('/api/v1/my/workspaces');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'domain',
                        'status',
                        'settings',
                        'owner' => [
                            'id',
                            'name',
                            'email',
                        ],
                        'member_count',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ])
            ->assertJsonPath('data.0.name', 'Test Workspace')
            ->assertJsonPath('data.0.slug', 'test-workspace')
            ->assertJsonPath('data.0.domain', 'test.example.com')
            ->assertJsonPath('data.0.status', 'active');
    });
});
