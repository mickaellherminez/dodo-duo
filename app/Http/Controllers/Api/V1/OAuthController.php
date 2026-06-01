<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;

class OAuthController extends Controller
{
    public function googleRedirect(): RedirectResponse
    {
        return $this->redirectToProvider('google', ['email', 'profile']);
    }

    public function googleCallback(Request $request): JsonResponse
    {
        return $this->handleProviderCallback('google', $request);
    }

    public function githubRedirect(): RedirectResponse
    {
        return $this->redirectToProvider('github', ['user:email']);
    }

    public function githubCallback(Request $request): JsonResponse
    {
        return $this->handleProviderCallback('github', $request);
    }

    private function redirectToProvider(string $provider, array $scopes): RedirectResponse
    {
        $codeVerifier = $this->createCodeVerifier();
        $codeChallenge = $this->createCodeChallenge($codeVerifier);
        $state = $this->storeState($provider, $codeVerifier);

        return Socialite::driver($provider)
            ->stateless()
            ->scopes($scopes)
            ->with([
                'state' => $state,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256',
            ])
            ->redirect();
    }

    private function handleProviderCallback(string $provider, Request $request): JsonResponse
    {
        $codeVerifier = $this->consumeState($provider, $request);

        if (! $codeVerifier) {
            return response()->json(['message' => 'Invalid OAuth state.'], 422);
        }

        $socialiteUser = Socialite::driver($provider)
            ->stateless()
            ->with(['code_verifier' => $codeVerifier])
            ->user();

        [$email, $emailVerified] = $this->resolveProviderEmail($provider, $socialiteUser);

        if (! $email) {
            return response()->json(['message' => 'Email not available from provider.'], 422);
        }

        $providerIdField = $provider === 'google' ? 'google_id' : 'github_id';
        $providerId = $socialiteUser->getId();
        $avatar = $socialiteUser->getAvatar();

        $user = User::where('email', $email)->first();
        $emailVerifiedAt = $this->resolveEmailVerifiedAt($emailVerified, $user?->email_verified_at);

        if ($user) {
            $user->forceFill([
                $providerIdField => $providerId,
                'avatar' => $avatar ?? $user->avatar,
                'email_verified_at' => $emailVerifiedAt,
            ])->save();
        } else {
            $user = new User([
                'name' => $socialiteUser->getName() ?: ($socialiteUser->getNickname() ?: $email),
                'email' => $email,
                'password' => Str::random(32),
                $providerIdField => $providerId,
                'avatar' => $avatar,
            ]);

            $user->forceFill(['email_verified_at' => $emailVerifiedAt])->save();
        }

        $token = $this->createAuthToken($user);

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
            ],
        ], 200);
    }

    private function storeState(string $provider, string $codeVerifier): string
    {
        $state = Str::random(40);

        Cache::put(
            $this->stateKey($state),
            ['provider' => $provider, 'code_verifier' => $codeVerifier],
            now()->addMinutes(10)
        );

        return $state;
    }

    private function consumeState(string $provider, Request $request): ?string
    {
        $state = $request->string('state')->toString();

        if ($state === '') {
            return null;
        }

        $payload = Cache::pull($this->stateKey($state));

        if (! is_array($payload) || ($payload['provider'] ?? null) !== $provider) {
            return null;
        }

        return $payload['code_verifier'] ?? null;
    }

    private function resolveProviderEmail(string $provider, SocialiteUserContract $socialiteUser): array
    {
        $email = $socialiteUser->getEmail();
        $verified = null;

        if ($provider === 'google') {
            $raw = method_exists($socialiteUser, 'getRaw')
                ? $socialiteUser->getRaw()
                : ($socialiteUser->user ?? []);
            $verified = $raw['email_verified'] ?? null;
        }

        if ($provider === 'github') {
            if (! $email) {
                [$email, $verified] = $this->fetchGithubPrimaryEmail($socialiteUser->token);
            } else {
                $verified = true;
            }
        }

        return [$email, $verified];
    }

    private function resolveEmailVerifiedAt(?bool $verified, ?Carbon $existing): ?Carbon
    {
        if ($verified === true) {
            return now();
        }

        if ($verified === false) {
            return null;
        }

        return $existing;
    }

    private function createAuthToken(User $user): string
    {
        $expirationMinutes = config('sanctum.expiration', 1440);

        return $user->createToken(
            'auth-token',
            ['*'],
            now()->addMinutes($expirationMinutes)
        )->plainTextToken;
    }

    private function stateKey(string $state): string
    {
        return "oauth_state:{$state}";
    }

    private function createCodeVerifier(): string
    {
        return Str::random(64);
    }

    private function createCodeChallenge(string $verifier): string
    {
        $hashed = hash('sha256', $verifier, true);

        return rtrim(strtr(base64_encode($hashed), '+/', '-_'), '=');
    }

    private function fetchGithubPrimaryEmail(?string $token): array
    {
        if (! $token) {
            return [null, null];
        }

        $response = Http::withToken($token)
            ->accept('application/vnd.github.v3+json')
            ->get('https://api.github.com/user/emails');

        if (! $response->ok()) {
            return [null, null];
        }

        $emails = $response->json();
        if (! is_array($emails)) {
            return [null, null];
        }

        foreach ($emails as $email) {
            if (($email['primary'] ?? false) === true) {
                return [$email['email'] ?? null, $email['verified'] ?? null];
            }
        }

        return [null, null];
    }
}
