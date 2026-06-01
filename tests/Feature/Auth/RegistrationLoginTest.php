<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('User Registration', function () {
    it('successfully registers a new user with valid data', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email', 'email_verified_at', 'created_at'],
                    'token',
                ],
            ])
            ->assertJson([
                'data' => [
                    'user' => [
                        'name' => 'Test User',
                        'email' => 'test@example.com',
                        'email_verified_at' => null,
                    ],
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Verify user was created
        $user = User::where('email', 'test@example.com')->first();
        expect($user)->not->toBeNull();
        expect($user->email_verified_at)->toBeNull(); // Not verified yet
        expect($user->tokens)->toHaveCount(1); // Token created
    });

    it('requires name field for registration', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    });

    it('requires email field for registration', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    });

    it('requires valid email format', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'invalid-email',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    });

    it('rejects registration with duplicate email', function () {
        // Create existing user
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'existing@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    });

    it('requires password field for registration', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    });

    it('requires password to be at least 8 characters', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    });

    it('requires password confirmation to match', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'different',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    });

    it('hashes password using bcrypt', function () {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $user = User::where('email', 'test@example.com')->first();

        // Verify password is hashed (not plain text)
        expect($user->password)->not->toBe('password123');

        // Verify bcrypt hash format (starts with $2y$)
        expect($user->password)->toStartWith('$2y$');

        // Verify password can be verified
        expect(\Hash::check('password123', $user->password))->toBeTrue();
    });

    it('creates sanctum token with configurable expiration', function () {
        config(['sanctum.expiration' => 1440]); // 24 hours

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $user = User::where('email', 'test@example.com')->first();
        $token = $user->tokens()->first();

        expect($token)->not->toBeNull();
        expect($token->expires_at)->not->toBeNull();

        // Token should expire approximately 24 hours from now (allow 1 minute variance)
        $expectedExpiry = now()->addMinutes(1440);
        expect($token->expires_at->diffInMinutes($expectedExpiry, false))->toBeLessThan(1);
    });

    it('sets email_verified_at to null on registration', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'user' => [
                        'email_verified_at' => null,
                    ],
                ],
            ]);

        $user = User::where('email', 'test@example.com')->first();
        expect($user->email_verified_at)->toBeNull();
    });
});

describe('User Login', function () {
    it('successfully logs in with valid credentials', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'token',
                ],
            ])
            ->assertJson([
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                ],
            ]);

        // Verify new token was created
        expect($user->tokens()->count())->toBe(1);
    });

    it('rejects login with invalid password', function () {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email'])
            ->assertJson([
                'errors' => [
                    'email' => ['Invalid credentials.'],
                ],
            ]);
    });

    it('rejects login with non-existent email', function () {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email'])
            ->assertJson([
                'errors' => [
                    'email' => ['Invalid credentials.'],
                ],
            ]);
    });

    it('does not leak whether email exists (generic error)', function () {
        // Create a user
        User::factory()->create([
            'email' => 'existing@example.com',
            'password' => 'password123',
        ]);

        // Wrong password for existing email
        $response1 = $this->postJson('/api/v1/auth/login', [
            'email' => 'existing@example.com',
            'password' => 'wrongpassword',
        ]);

        // Non-existent email
        $response2 = $this->postJson('/api/v1/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'password123',
        ]);

        // Both should return same generic error
        expect($response1->json('errors.email.0'))->toBe('Invalid credentials.');
        expect($response2->json('errors.email.0'))->toBe('Invalid credentials.');
    });

    it('requires email field for login', function () {
        $response = $this->postJson('/api/v1/auth/login', [
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    });

    it('requires password field for login', function () {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    });

    it('creates sanctum token with 24-hour expiration on login', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        config(['sanctum.expiration' => 1440]); // 24 hours

        $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $token = $user->tokens()->first();
        expect($token->expires_at)->not->toBeNull();

        $expectedExpiry = now()->addMinutes(1440);
        expect($token->expires_at->diffInMinutes($expectedExpiry, false))->toBeLessThan(1);
    });
});

describe('User Logout', function () {
    it('successfully logs out and revokes current token', function () {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Logged out successfully.',
            ]);

        // Verify token was revoked
        expect($user->tokens()->count())->toBe(0);
    });

    it('deletes token from database when logging out', function () {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        // Verify token exists before logout
        expect($user->tokens()->count())->toBe(1);

        // Logout (revoke token)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertStatus(200);

        // Verify token was deleted from database
        $user->refresh();
        expect($user->tokens()->count())->toBe(0);
    });

    it('requires authentication for logout', function () {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(401);
    });
});

describe('Logout from All Devices', function () {
    it('revokes all user tokens', function () {
        $user = User::factory()->create();

        // Create multiple tokens (simulating multiple devices)
        $token1 = $user->createToken('device-1')->plainTextToken;
        $token2 = $user->createToken('device-2')->plainTextToken;
        $token3 = $user->createToken('device-3')->plainTextToken;

        // Verify 3 tokens exist
        expect($user->tokens()->count())->toBe(3);

        // Logout from all devices using token1
        $response = $this->withHeader('Authorization', 'Bearer '.$token1)
            ->postJson('/api/v1/auth/logout-all');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Logged out from all devices successfully.',
            ]);

        // Verify all tokens were revoked
        $user->refresh();
        expect($user->tokens()->count())->toBe(0);
    });

    it('deletes all tokens from database after logout-all', function () {
        $user = User::factory()->create();

        $token1 = $user->createToken('device-1')->plainTextToken;
        $token2 = $user->createToken('device-2')->plainTextToken;

        // Verify 2 tokens exist
        expect($user->tokens()->count())->toBe(2);

        // Logout all
        $this->withHeader('Authorization', 'Bearer '.$token1)
            ->postJson('/api/v1/auth/logout-all')
            ->assertStatus(200);

        // Verify all tokens were deleted from database
        $user->refresh();
        expect($user->tokens()->count())->toBe(0);
    });

    it('requires authentication for logout-all', function () {
        $response = $this->postJson('/api/v1/auth/logout-all');

        $response->assertStatus(401);
    });
});
