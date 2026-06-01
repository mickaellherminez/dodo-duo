<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

describe('Profile', function () {
    test('can retrieve authenticated user profile', function () {
        $user = User::factory()->create([
            'avatar' => 'https://example.com/avatar.png',
            'google_id' => 'google-123',
            'github_id' => 'github-456',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/me');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'email',
                    'email_verified_at',
                    'avatar',
                    'google_id',
                    'github_id',
                    'created_at',
                ],
            ])
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.avatar', 'https://example.com/avatar.png');
    });

    test('can update profile name', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/me', [
            'name' => 'Updated Name',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');

        $user->refresh();
        expect($user->name)->toBe('Updated Name');
    });

    test('email change resets verification and sends new email', function () {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'old@example.com',
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/me', [
            'email' => 'new@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.email', 'new@example.com');

        $user->refresh();
        expect($user->email_verified_at)->toBeNull();

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    });

    test('can change password with valid current password', function () {
        $user = User::factory()->create([
            'password' => 'old-password',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/me/change-password', [
            'current_password' => 'old-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Password updated successfully.');

        $user->refresh();
        expect(Hash::check('new-password-123', $user->password))->toBeTrue();
    });

    test('change password fails with invalid current password', function () {
        $user = User::factory()->create([
            'password' => 'old-password',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/me/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Current password is incorrect.');
    });
});
