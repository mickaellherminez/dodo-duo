<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create users
    $this->userA = User::factory()->create(['name' => 'User A', 'email' => 'usera@test.com']);
    $this->userB = User::factory()->create(['name' => 'User B', 'email' => 'userb@test.com']);

    // Create workspaces
    $this->workspaceA = Workspace::factory()->create([
        'name' => 'Workspace A',
        'slug' => 'workspace-a',
        'owner_id' => $this->userA->id,
    ]);

    $this->workspaceB = Workspace::factory()->create([
        'name' => 'Workspace B',
        'slug' => 'workspace-b',
        'owner_id' => $this->userB->id,
    ]);

    // Add UserA to WorkspaceA as owner
    WorkspaceMember::create([
        'workspace_id' => $this->workspaceA->id,
        'user_id' => $this->userA->id,
        'role' => 'owner',
        'joined_at' => now(),
    ]);

    // Add UserB to WorkspaceB as owner
    WorkspaceMember::create([
        'workspace_id' => $this->workspaceB->id,
        'user_id' => $this->userB->id,
        'role' => 'owner',
        'joined_at' => now(),
    ]);
});

test('user can switch to a workspace they belong to', function () {
    Sanctum::actingAs($this->userA);

    $response = $this->postJson("/api/v1/my/workspaces/{$this->workspaceA->id}/switch");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'message',
            'workspace' => ['id', 'name', 'slug'],
            'token',
        ])
        ->assertJson([
            'message' => 'Workspace switched successfully.',
            'workspace' => [
                'id' => $this->workspaceA->id,
                'slug' => 'workspace-a',
            ],
        ]);

    expect($response->json('token'))->not->toBeNull();
});

test('user cannot switch to a workspace they do not belong to', function () {
    Sanctum::actingAs($this->userA);

    // Try to switch to WorkspaceB (UserA is not a member)
    $response = $this->postJson("/api/v1/my/workspaces/{$this->workspaceB->id}/switch");

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'This action is unauthorized.',
        ]);
});

test('issued token contains workspace context in abilities', function () {
    Sanctum::actingAs($this->userA);

    $response = $this->postJson("/api/v1/my/workspaces/{$this->workspaceA->id}/switch");

    $response->assertStatus(200);

    $token = $response->json('token');
    expect($token)->not->toBeNull();

    // Verify token works and has correct abilities
    $tokenModel = $this->userA->tokens()->latest()->first();
    expect($tokenModel)->not->toBeNull();
    expect($tokenModel->abilities)->toContain("workspace:{$this->workspaceA->id}");
});

test('subsequent requests with workspace token use correct context', function () {
    // Create a real token for initial authentication (different name!)
    $initialToken = $this->userA->createToken('initial-token');

    // Switch to WorkspaceA using the real token
    $switchResponse = $this->withHeaders([
        'Authorization' => "Bearer {$initialToken->plainTextToken}",
    ])->postJson("/api/v1/my/workspaces/{$this->workspaceA->id}/switch");

    // Get the new token with workspace context
    $token = $switchResponse->json('token');

    // IMPORTANT: Reset auth state so next request uses the new token from header
    auth()->guard('sanctum')->forgetUser();
    $this->app->forgetInstance('auth');

    // Make request with the new token
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
    ])->getJson('/api/v1/my/current-workspace');

    $response->assertStatus(200)
        ->assertJson([
            'data' => [
                'id' => $this->workspaceA->id,
                'slug' => 'workspace-a',
            ],
        ]);
});

test('middleware resolves workspace from token abilities', function () {
    // Create a token with workspace abilities
    $token = $this->userA->createToken('api', ["workspace:{$this->workspaceA->id}"]);

    // Make request with the token
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token->plainTextToken}",
    ])->getJson('/api/v1/my/current-workspace');

    $response->assertStatus(200)
        ->assertJson([
            'data' => [
                'id' => $this->workspaceA->id,
                'slug' => 'workspace-a',
            ],
        ]);

    // Verify current_workspace() was set correctly
    expect(current_workspace())->not->toBeNull();
    expect(current_workspace()->id())->toBe($this->workspaceA->id);
});

test('current workspace endpoint returns 404 when no context is set', function () {
    Sanctum::actingAs($this->userA);

    // Make request without workspace context
    $response = $this->getJson('/api/v1/my/current-workspace');

    $response->assertStatus(404)
        ->assertJson([
            'message' => 'Resource not found.',
        ]);
});

test('current workspace endpoint returns workspace details with user role', function () {
    // Create token with workspace context
    $token = $this->userA->createToken('api', ["workspace:{$this->workspaceA->id}"]);

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token->plainTextToken}",
    ])->getJson('/api/v1/my/current-workspace');

    $response->assertStatus(200)
        ->assertJson([
            'data' => [
                'id' => $this->workspaceA->id,
                'name' => 'Workspace A',
                'slug' => 'workspace-a',
            ],
            'user_role' => 'owner',
        ]);
});

test('user can switch between multiple workspaces', function () {
    // Add UserA to WorkspaceB as a member
    WorkspaceMember::create([
        'workspace_id' => $this->workspaceB->id,
        'user_id' => $this->userA->id,
        'role' => 'member',
        'joined_at' => now(),
    ]);

    // Create initial real token (unique name)
    $initialToken = $this->userA->createToken('initial-multi-test');

    // Switch to WorkspaceA
    $responseA = $this->withHeaders([
        'Authorization' => "Bearer {$initialToken->plainTextToken}",
    ])->postJson("/api/v1/my/workspaces/{$this->workspaceA->id}/switch");
    $responseA->assertStatus(200);
    $tokenA = $responseA->json('token');

    // Reset auth and verify context is WorkspaceA
    auth()->guard('sanctum')->forgetUser();
    $this->app->forgetInstance('auth');
    $currentA = $this->withHeaders(['Authorization' => "Bearer {$tokenA}"])
        ->getJson('/api/v1/my/current-workspace');
    $currentA->assertJson(['data' => ['id' => $this->workspaceA->id]]);

    // Reset auth for next switch
    auth()->guard('sanctum')->forgetUser();
    $this->app->forgetInstance('auth');

    // Switch to WorkspaceB
    $responseB = $this->withHeaders([
        'Authorization' => "Bearer {$tokenA}",  // Use tokenA to switch
    ])->postJson("/api/v1/my/workspaces/{$this->workspaceB->id}/switch");
    $responseB->assertStatus(200);
    $tokenB = $responseB->json('token');

    // Reset auth and verify context is WorkspaceB
    auth()->guard('sanctum')->forgetUser();
    $this->app->forgetInstance('auth');
    $currentB = $this->withHeaders(['Authorization' => "Bearer {$tokenB}"])
        ->getJson('/api/v1/my/current-workspace');
    $currentB->assertJson(['data' => ['id' => $this->workspaceB->id]]);
});

test('switching workspace resets previous context', function () {
    // Add UserA to both workspaces
    WorkspaceMember::create([
        'workspace_id' => $this->workspaceB->id,
        'user_id' => $this->userA->id,
        'role' => 'member',
        'joined_at' => now(),
    ]);

    // Create initial real token (unique name)
    $initialToken = $this->userA->createToken('initial-reset-test');

    // Switch to WorkspaceA
    $responseA = $this->withHeaders([
        'Authorization' => "Bearer {$initialToken->plainTextToken}",
    ])->postJson("/api/v1/my/workspaces/{$this->workspaceA->id}/switch");
    $tokenA = $responseA->json('token');

    // Reset auth before switching to B
    auth()->guard('sanctum')->forgetUser();
    $this->app->forgetInstance('auth');

    // Switch to WorkspaceB
    $responseB = $this->withHeaders([
        'Authorization' => "Bearer {$tokenA}",
    ])->postJson("/api/v1/my/workspaces/{$this->workspaceB->id}/switch");
    $tokenB = $responseB->json('token');

    // Verify old token still points to WorkspaceA, new token points to WorkspaceB
    auth()->guard('sanctum')->forgetUser();
    $this->app->forgetInstance('auth');
    $checkA = $this->withHeaders(['Authorization' => "Bearer {$tokenA}"])
        ->getJson('/api/v1/my/current-workspace');
    $checkA->assertJson(['data' => ['id' => $this->workspaceA->id]]);

    auth()->guard('sanctum')->forgetUser();
    $this->app->forgetInstance('auth');
    $checkB = $this->withHeaders(['Authorization' => "Bearer {$tokenB}"])
        ->getJson('/api/v1/my/current-workspace');
    $checkB->assertJson(['data' => ['id' => $this->workspaceB->id]]);
});
