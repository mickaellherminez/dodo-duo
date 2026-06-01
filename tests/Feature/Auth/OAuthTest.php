<?php

use App\Http\Controllers\Api\V1\OAuthController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\RedirectResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

test('google redirect stores state and redirects', function () {
    $verifier = str_repeat('a', 64);
    $state = 'state-123';
    $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $calls = [$verifier, $state];
    Str::createRandomStringsUsing(function () use (&$calls) {
        return array_shift($calls);
    });

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('scopes')->with(['email', 'profile'])->andReturnSelf();
    $provider->shouldReceive('with')->with(Mockery::on(function ($params) use ($state, $expectedChallenge) {
        return $params['state'] === $state
            && $params['code_challenge'] === $expectedChallenge
            && $params['code_challenge_method'] === 'S256';
    }))
        ->andReturnSelf();
    $provider->shouldReceive('redirect')->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2/auth', 302));

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $response = app(OAuthController::class)->googleRedirect();

    expect($response)->toBeInstanceOf(RedirectResponse::class);
    expect(Cache::get('oauth_state:state-123'))->toMatchArray([
        'provider' => 'google',
        'code_verifier' => $verifier,
    ]);

    Str::createRandomStringsUsing();
});

test('google callback creates new user and returns token', function () {
    Cache::put('oauth_state:state-123', ['provider' => 'google', 'code_verifier' => 'verifier-123'], now()->addMinutes(10));

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'google-123';
    $socialiteUser->name = 'Google User';
    $socialiteUser->email = 'google@example.com';
    $socialiteUser->avatar = 'https://example.com/avatar.png';
    $socialiteUser->user = ['email_verified' => true];

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('with')->with(['code_verifier' => 'verifier-123'])->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $request = Request::create('/api/v1/auth/google/callback', 'GET', ['state' => 'state-123', 'code' => 'code']);
    $response = app(OAuthController::class)->googleCallback($request);

    expect($response->getStatusCode())->toBe(200);
    $payload = $response->getData(true);

    expect($payload['data']['user']['email'])->toBe('google@example.com')
        ->and($payload['data']['token'])->not->toBeEmpty();

    $user = User::where('email', 'google@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->google_id)->toBe('google-123')
        ->and($user->email_verified_at)->not->toBeNull();

    expect(Cache::has('oauth_state:state-123'))->toBeFalse();
});

test('google callback links existing user', function () {
    $existing = User::factory()->create(['email' => 'existing@example.com']);

    Cache::put('oauth_state:state-456', ['provider' => 'google', 'code_verifier' => 'verifier-456'], now()->addMinutes(10));

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'google-456';
    $socialiteUser->name = 'Existing User';
    $socialiteUser->email = 'existing@example.com';
    $socialiteUser->avatar = null;
    $socialiteUser->user = ['email_verified' => true];

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('with')->with(['code_verifier' => 'verifier-456'])->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $request = Request::create('/api/v1/auth/google/callback', 'GET', ['state' => 'state-456', 'code' => 'code']);
    $response = app(OAuthController::class)->googleCallback($request);

    expect($response->getStatusCode())->toBe(200);
    expect(User::count())->toBe(1);

    $existing->refresh();
    expect($existing->google_id)->toBe('google-456');
});

test('github callback creates new user and returns token', function () {
    Cache::put('oauth_state:state-789', ['provider' => 'github', 'code_verifier' => 'verifier-789'], now()->addMinutes(10));

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'github-789';
    $socialiteUser->name = 'GitHub User';
    $socialiteUser->email = 'github@example.com';
    $socialiteUser->avatar = 'https://example.com/github.png';
    $socialiteUser->user = [];

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('with')->with(['code_verifier' => 'verifier-789'])->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->with('github')->andReturn($provider);

    $request = Request::create('/api/v1/auth/github/callback', 'GET', ['state' => 'state-789', 'code' => 'code']);
    $response = app(OAuthController::class)->githubCallback($request);

    expect($response->getStatusCode())->toBe(200);
    $payload = $response->getData(true);

    expect($payload['data']['user']['email'])->toBe('github@example.com')
        ->and($payload['data']['token'])->not->toBeEmpty();

    $user = User::where('email', 'github@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->github_id)->toBe('github-789')
        ->and($user->email_verified_at)->not->toBeNull();
});

test('callback rejects invalid state', function () {
    Socialite::shouldReceive('driver')->never();

    $request = Request::create('/api/v1/auth/google/callback', 'GET', ['state' => 'missing', 'code' => 'code']);
    $response = app(OAuthController::class)->googleCallback($request);

    expect($response->getStatusCode())->toBe(422);
});

test('github callback rejects invalid state', function () {
    Socialite::shouldReceive('driver')->never();

    $request = Request::create('/api/v1/auth/github/callback', 'GET', ['state' => 'missing', 'code' => 'code']);
    $response = app(OAuthController::class)->githubCallback($request);

    expect($response->getStatusCode())->toBe(422);
});
