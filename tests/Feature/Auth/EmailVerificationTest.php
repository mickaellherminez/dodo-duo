<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

describe('Email Verification', function () {
    test('sends verification email on registration', function () {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'test@example.com')->first();
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    });

    test('can resend verification email', function () {
        Notification::fake();

        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/email/verification-notification');

        $response->assertOk()
            ->assertJsonPath('message', 'Verification email sent.');

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    });

    test('resend returns already verified message', function () {
        Notification::fake();

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/email/verification-notification');

        $response->assertOk()
            ->assertJsonPath('message', 'Email already verified.');

        Notification::assertNothingSent();
    });

    test('verification link marks email as verified', function () {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
        );

        $response = $this->getJson($url);

        $response->assertOk()
            ->assertJsonPath('message', 'Email verified successfully.');

        $user->refresh();
        expect($user->hasVerifiedEmail())->toBeTrue();
    });

    test('verified middleware blocks unverified users with action', function () {
        Route::middleware(['auth:sanctum', 'verified'])->get('/api/v1/verified-test', function () {
            return response()->json(['ok' => true]);
        });

        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/verified-test');

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Email not verified.')
            ->assertJsonStructure([
                'action' => ['type', 'endpoint'],
            ]);
    });
});
