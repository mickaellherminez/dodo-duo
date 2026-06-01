<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Clear notification fake between tests
    Notification::fake();
});

/*
|--------------------------------------------------------------------------
| Forgot Password Tests
|--------------------------------------------------------------------------
*/

describe('Forgot Password', function () {
    test('sends password reset email for existing user', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'If your email is registered, you will receive a password reset link.',
            ]);

        // Verify notification was sent
        Notification::assertSentTo($user, ResetPasswordNotification::class);

        // Verify token was stored in database
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'test@example.com',
        ]);
    });

    test('returns success for non-existent email (security)', function () {
        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        // Same response to prevent email enumeration
        $response->assertStatus(200)
            ->assertJson([
                'message' => 'If your email is registered, you will receive a password reset link.',
            ]);

        // No notification should be sent
        Notification::assertNothingSent();

        // No token should be stored
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'nonexistent@example.com',
        ]);
    });

    test('replaces existing token on new request', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        // First request
        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'test@example.com',
        ]);

        $firstToken = DB::table('password_reset_tokens')
            ->where('email', 'test@example.com')
            ->value('token');

        // Second request
        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'test@example.com',
        ]);

        $secondToken = DB::table('password_reset_tokens')
            ->where('email', 'test@example.com')
            ->value('token');

        // Token should be different
        expect($secondToken)->not->toBe($firstToken);

        // Only one record should exist
        $tokenCount = DB::table('password_reset_tokens')
            ->where('email', 'test@example.com')
            ->count();

        expect($tokenCount)->toBe(1);
    });

    test('validates email format', function () {
        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    });

    test('requires email field', function () {
        $response = $this->postJson('/api/v1/auth/forgot-password', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    });
});

/*
|--------------------------------------------------------------------------
| Reset Password Tests
|--------------------------------------------------------------------------
*/

describe('Reset Password', function () {
    test('resets password with valid token', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'old-password',
        ]);

        // Create a reset token
        $plainToken = 'test-reset-token-12345';
        DB::table('password_reset_tokens')->insert([
            'email' => 'test@example.com',
            'token' => Hash::make($plainToken),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'test@example.com',
            'token' => $plainToken,
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Password has been reset successfully. Please login with your new password.',
            ]);

        // Verify password was changed
        $user->refresh();
        expect(Hash::check('new-password123', $user->password))->toBeTrue();

        // Verify token was deleted
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'test@example.com',
        ]);
    });

    test('revokes all tokens after password reset', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        // Create some tokens for the user
        $user->createToken('token-1');
        $user->createToken('token-2');
        $user->createToken('token-3');

        expect($user->tokens()->count())->toBe(3);

        // Create a reset token
        $plainToken = 'test-reset-token-12345';
        DB::table('password_reset_tokens')->insert([
            'email' => 'test@example.com',
            'token' => Hash::make($plainToken),
            'created_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'test@example.com',
            'token' => $plainToken,
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ]);

        // All tokens should be revoked
        expect($user->tokens()->count())->toBe(0);
    });

    test('rejects invalid token', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        // Create a reset token
        $plainToken = 'correct-token';
        DB::table('password_reset_tokens')->insert([
            'email' => 'test@example.com',
            'token' => Hash::make($plainToken),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'test@example.com',
            'token' => 'wrong-token',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    });

    test('rejects expired token', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        // Create an expired reset token (61 minutes ago)
        $plainToken = 'expired-token';
        DB::table('password_reset_tokens')->insert([
            'email' => 'test@example.com',
            'token' => Hash::make($plainToken),
            'created_at' => now()->subMinutes(61),
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'test@example.com',
            'token' => $plainToken,
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['token']);

        // Expired token should be deleted
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'test@example.com',
        ]);
    });

    test('rejects request for non-existent email', function () {
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'nonexistent@example.com',
            'token' => 'some-token',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    });

    test('validates password minimum length', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $plainToken = 'test-token';
        DB::table('password_reset_tokens')->insert([
            'email' => 'test@example.com',
            'token' => Hash::make($plainToken),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'test@example.com',
            'token' => $plainToken,
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    });

    test('validates password confirmation matches', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $plainToken = 'test-token';
        DB::table('password_reset_tokens')->insert([
            'email' => 'test@example.com',
            'token' => Hash::make($plainToken),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'test@example.com',
            'token' => $plainToken,
            'password' => 'new-password123',
            'password_confirmation' => 'different-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    });

    test('requires all fields', function () {
        $response = $this->postJson('/api/v1/auth/reset-password', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'token', 'password']);
    });
});

/*
|--------------------------------------------------------------------------
| End-to-End Password Reset Flow
|--------------------------------------------------------------------------
*/

describe('End-to-End Password Reset', function () {
    test('complete password reset flow works', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'original-password',
        ]);

        // Step 1: Request password reset
        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(200);

        // Get the token from notification
        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use (&$capturedToken) {
            $capturedToken = $notification->token;

            return true;
        });

        expect($capturedToken)->not->toBeNull();

        // Step 2: Reset password with token
        Notification::fake(); // Reset notification fake

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'test@example.com',
            'token' => $capturedToken,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertStatus(200);

        // Step 3: Verify can login with new password
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'brand-new-password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'token',
                ],
            ]);

        // Step 4: Verify cannot login with old password
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'original-password',
        ]);

        $response->assertStatus(422);
    });

    test('token expiration is configurable', function () {
        // Set a very short expiration time
        config(['auth.passwords.users.expire' => 1]); // 1 minute

        $user = User::factory()->create([
            'email' => 'test@example.com',
        ]);

        $plainToken = 'test-token';
        DB::table('password_reset_tokens')->insert([
            'email' => 'test@example.com',
            'token' => Hash::make($plainToken),
            'created_at' => now()->subMinutes(2), // 2 minutes ago
        ]);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'test@example.com',
            'token' => $plainToken,
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ]);

        // Should be expired
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    });
});

/*
|--------------------------------------------------------------------------
| Rate Limiting Tests
|--------------------------------------------------------------------------
*/

describe('Rate Limiting', function () {
    test('forgot-password endpoint is rate limited', function () {
        // Make 6 requests (limit is 5/minute)
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/v1/auth/forgot-password', [
                'email' => "test{$i}@example.com",
            ]);
            $response->assertStatus(200);
        }

        // 6th request should be rate limited
        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'rate-limited@example.com',
        ]);

        $response->assertStatus(429);
    });

    test('reset-password endpoint is rate limited', function () {
        // Make 6 requests (limit is 5/minute)
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/v1/auth/reset-password', [
                'email' => "test{$i}@example.com",
                'token' => 'fake-token',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);
            // Will fail validation but not rate limited
            $response->assertStatus(422);
        }

        // 6th request should be rate limited
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'rate-limited@example.com',
            'token' => 'fake-token',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(429);
    });
});

/*
|--------------------------------------------------------------------------
| Notification Tests
|--------------------------------------------------------------------------
*/

describe('Reset Password Notification', function () {
    test('notification contains correct reset link', function () {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'test@example.com',
        ]);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) {
            // Verify the notification has the correct email
            expect($notification->email)->toBe('test@example.com');
            // Verify the token is set
            expect($notification->token)->not->toBeEmpty();

            return true;
        });
    });

    test('notification is queued', function () {
        $notification = new ResetPasswordNotification('test-token', 'test@example.com');

        // The notification implements ShouldQueue
        expect($notification)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
    });
});
