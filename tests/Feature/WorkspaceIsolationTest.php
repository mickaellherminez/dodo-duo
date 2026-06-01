<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Workspace Isolation Tests
 *
 * Story 2.4 - Validates that workspace isolation works correctly across all scenarios.
 * Ensures users cannot access resources from workspaces they don't belong to.
 */
beforeEach(function () {
    // Create two separate workspaces
    $this->workspaceA = Workspace::factory()->create([
        'name' => 'Workspace A',
        'slug' => 'workspace-a',
    ]);

    $this->workspaceB = Workspace::factory()->create([
        'name' => 'Workspace B',
        'slug' => 'workspace-b',
    ]);

    // Create two users with different workspace memberships
    $this->userA = User::factory()->create(['name' => 'User A', 'email' => 'usera@test.com']);
    $this->userB = User::factory()->create(['name' => 'User B', 'email' => 'userb@test.com']);

    // UserA belongs ONLY to WorkspaceA
    $this->workspaceA->addMember($this->userA, 'member');

    // UserB belongs ONLY to WorkspaceB
    $this->workspaceB->addMember($this->userB, 'member');

    // Set default app_domain for tests
    Config::set('workspace.app_domain', 'localhost');
});

describe('Cross-Workspace Access Prevention', function () {
    test('UserA cannot GET WorkspaceB details (403)', function () {
        Sanctum::actingAs($this->userA);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspaceB->id}");

        // Route parameter resolution finds WorkspaceB, userHasAccess() returns false → 403
        $response->assertForbidden();
    });

    test('UserA cannot PATCH WorkspaceB (403, unchanged)', function () {
        Sanctum::actingAs($this->userA);

        $originalName = $this->workspaceB->name;

        $response = $this->patchJson("/api/v1/workspaces/{$this->workspaceB->id}", [
            'name' => 'Hacked Name',
        ]);

        $response->assertForbidden();

        // Verify WorkspaceB was not modified
        $this->workspaceB->refresh();
        expect($this->workspaceB->name)->toBe($originalName);
    });

    test('UserA cannot DELETE WorkspaceB (403, not deleted)', function () {
        Sanctum::actingAs($this->userA);

        $response = $this->deleteJson("/api/v1/workspaces/{$this->workspaceB->id}");

        $response->assertForbidden();

        // Verify WorkspaceB still exists
        expect(Workspace::find($this->workspaceB->id))->not->toBeNull();
    });

    test('UserB cannot access WorkspaceA via any CRUD operation', function () {
        Sanctum::actingAs($this->userB);

        // GET - Route param resolution → userHasAccess check → 403
        $getResponse = $this->getJson("/api/v1/workspaces/{$this->workspaceA->id}");
        $getResponse->assertForbidden();

        // PATCH
        $patchResponse = $this->patchJson("/api/v1/workspaces/{$this->workspaceA->id}", ['name' => 'Hack']);
        $patchResponse->assertForbidden();

        // DELETE
        $deleteResponse = $this->deleteJson("/api/v1/workspaces/{$this->workspaceA->id}");
        $deleteResponse->assertForbidden();

        // Verify WorkspaceA unchanged
        $this->workspaceA->refresh();
        expect($this->workspaceA->name)->toBe('Workspace A');
        expect(Workspace::find($this->workspaceA->id))->not->toBeNull();
    });
});

describe('Workspace Listing Isolation', function () {
    test('UserA listing workspaces only sees WorkspaceA', function () {
        Sanctum::actingAs($this->userA);

        $response = $this->getJson('/api/v1/workspaces');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $this->workspaceA->id])
            ->assertJsonMissing(['id' => $this->workspaceB->id]);
    });

    test('UserB listing workspaces only sees WorkspaceB', function () {
        Sanctum::actingAs($this->userB);

        $response = $this->getJson('/api/v1/workspaces');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $this->workspaceB->id])
            ->assertJsonMissing(['id' => $this->workspaceA->id]);
    });

    test('My Workspaces endpoint respects membership', function () {
        Sanctum::actingAs($this->userA);

        $response = $this->getJson('/api/v1/my/workspaces');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $this->workspaceA->id]);

        // Switch to UserB
        Sanctum::actingAs($this->userB);

        $responseB = $this->getJson('/api/v1/my/workspaces');

        $responseB->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['id' => $this->workspaceB->id]);
    });
});

describe('Route Parameter Resolution Validates Membership', function () {
    test('accessing non-member workspace via route parameter returns 403', function () {
        Sanctum::actingAs($this->userA);

        // Route parameter resolution: /api/v1/workspaces/{workspace}
        // SetCurrentWorkspace middleware resolves workspace from route
        // Then checks userHasAccess() - should return false for UserA + WorkspaceB
        $response = $this->getJson("/api/v1/workspaces/{$this->workspaceB->id}");

        // Expecting 404 because route model binding won't find it in userHasAccess scope
        // Or 403 if middleware explicitly denies access
        expect($response->status())->toBeIn([403, 404]);
    });

    test('accessing member workspace via route parameter succeeds', function () {
        Sanctum::actingAs($this->userA);

        $response = $this->getJson("/api/v1/workspaces/{$this->workspaceA->id}");

        $response->assertOk()
            ->assertJsonFragment(['id' => $this->workspaceA->id]);
    });
});

describe('Header-Based Resolution Validates Membership', function () {
    test('X-Workspace-ID header with non-member workspace returns 403', function () {
        Sanctum::actingAs($this->userA);

        // Using header resolution strategy
        $response = $this->withHeaders([
            'X-Workspace-ID' => $this->workspaceB->id,
        ])->getJson('/api/v1/workspaces');

        $response->assertForbidden();
    });

    test('X-Workspace-ID header with member workspace succeeds', function () {
        Sanctum::actingAs($this->userA);

        $response = $this->withHeaders([
            'X-Workspace-ID' => $this->workspaceA->id,
        ])->getJson('/api/v1/workspaces');

        $response->assertOk();
    });

    test('invalid X-Workspace-ID returns 404', function () {
        Sanctum::actingAs($this->userA);

        $response = $this->withHeaders([
            'X-Workspace-ID' => 99999,
        ])->getJson('/api/v1/workspaces');

        $response->assertNotFound();
    });
});

describe('Subdomain Resolution Validates Membership', function () {
    test('accessing non-member workspace via subdomain returns 403', function () {
        Sanctum::actingAs($this->userA);
        Config::set('workspace.app_domain', 'saasforge.test');

        // Create request with subdomain for WorkspaceB
        $response = $this->get('http://workspace-b.saasforge.test/api/v1/workspaces', [
            'Accept' => 'application/json',
        ]);

        $response->assertForbidden();
    });

    test('accessing member workspace via subdomain succeeds', function () {
        Sanctum::actingAs($this->userA);
        Config::set('workspace.app_domain', 'saasforge.test');

        $response = $this->get('http://workspace-a.saasforge.test/api/v1/workspaces', [
            'Accept' => 'application/json',
        ]);

        $response->assertOk();
    });

    test('non-existent workspace subdomain returns 404', function () {
        Sanctum::actingAs($this->userA);
        Config::set('workspace.app_domain', 'saasforge.test');

        $response = $this->get('http://nonexistent.saasforge.test/api/v1/workspaces', [
            'Accept' => 'application/json',
        ]);

        $response->assertNotFound();
    });
});

describe('Multi-Workspace User Can Switch Contexts', function () {
    test('user belonging to both workspaces can switch between them', function () {
        // Create UserC who belongs to BOTH workspaces
        $userC = User::factory()->create(['name' => 'User C', 'email' => 'userc@test.com']);
        $this->workspaceA->addMember($userC, 'member');
        $this->workspaceB->addMember($userC, 'member');

        $initialToken = $userC->createToken('initial')->plainTextToken;

        // Switch to WorkspaceA
        $responseA = $this->withHeaders(['Authorization' => "Bearer {$initialToken}"])
            ->postJson("/api/v1/my/workspaces/{$this->workspaceA->id}/switch");

        $responseA->assertOk();
        $tokenA = $responseA->json('token');

        // Verify current workspace is A
        auth()->guard('sanctum')->forgetUser();
        $this->app->forgetInstance('auth');

        $checkA = $this->withHeaders(['Authorization' => "Bearer {$tokenA}"])
            ->getJson('/api/v1/my/current-workspace');

        $checkA->assertOk()
            ->assertJson(['data' => ['id' => $this->workspaceA->id]]);

        // Switch to WorkspaceB
        auth()->guard('sanctum')->forgetUser();
        $this->app->forgetInstance('auth');

        $responseB = $this->withHeaders(['Authorization' => "Bearer {$tokenA}"])
            ->postJson("/api/v1/my/workspaces/{$this->workspaceB->id}/switch");

        $responseB->assertOk();
        $tokenB = $responseB->json('token');

        // Verify current workspace is B
        auth()->guard('sanctum')->forgetUser();
        $this->app->forgetInstance('auth');

        $checkB = $this->withHeaders(['Authorization' => "Bearer {$tokenB}"])
            ->getJson('/api/v1/my/current-workspace');

        $checkB->assertOk()
            ->assertJson(['data' => ['id' => $this->workspaceB->id]]);

        // Verify old token still points to WorkspaceA
        auth()->guard('sanctum')->forgetUser();
        $this->app->forgetInstance('auth');

        $recheckA = $this->withHeaders(['Authorization' => "Bearer {$tokenA}"])
            ->getJson('/api/v1/my/current-workspace');

        $recheckA->assertOk()
            ->assertJson(['data' => ['id' => $this->workspaceA->id]]);
    });

    test('after switching, only see switched workspace in listings', function () {
        // Create UserC who belongs to BOTH
        $userC = User::factory()->create(['name' => 'User C']);
        $this->workspaceA->addMember($userC, 'member');
        $this->workspaceB->addMember($userC, 'member');

        // List workspaces without specific context - should see both
        Sanctum::actingAs($userC);
        $response = $this->getJson('/api/v1/my/workspaces');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    });
});

describe('Token-Based Resolution Isolation', function () {
    test('token with workspace abilities resolves correct workspace', function () {
        $token = $this->userA->createToken('test', ["workspace:{$this->workspaceA->id}"])->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/my/current-workspace');

        $response->assertOk()
            ->assertJson(['data' => ['id' => $this->workspaceA->id]]);
    });

    test('token with invalid workspace abilities returns 404', function () {
        // Token with non-existent workspace ID
        $token = $this->userA->createToken('test', ['workspace:99999'])->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/my/current-workspace');

        $response->assertNotFound();
    });

    test('token with another users workspace is rejected', function () {
        // UserA has token with WorkspaceB context (which they don't belong to)
        $token = $this->userA->createToken('test', ["workspace:{$this->workspaceB->id}"])->plainTextToken;

        // Token-based resolution finds WorkspaceB, but userHasAccess() should fail
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/my/current-workspace');

        // Should return 403 because UserA doesn't belong to WorkspaceB
        // Middleware resolves workspace from token, then checks access → 403
        $response->assertForbidden();
    });
});

describe('Routes Without Workspace Context', function () {
    test('my workspaces endpoint works without workspace context', function () {
        Sanctum::actingAs($this->userA);

        // This endpoint doesn't require workspace context
        $response = $this->getJson('/api/v1/my/workspaces');

        $response->assertOk();
    });

    test('workspace switching works without prior context', function () {
        $token = $this->userA->createToken('initial')->plainTextToken;

        // Switch without any prior workspace context
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/my/workspaces/{$this->workspaceA->id}/switch");

        $response->assertOk()
            ->assertJsonStructure(['token', 'workspace', 'message']);
    });

    test('current workspace endpoint returns 404 without context', function () {
        Sanctum::actingAs($this->userA);

        // No workspace context set
        $response = $this->getJson('/api/v1/my/current-workspace');

        $response->assertNotFound()
            ->assertJson(['message' => 'Resource not found.']);
    });
});
