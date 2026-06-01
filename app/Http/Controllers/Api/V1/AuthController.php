<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenApi\Annotations as OA;

class AuthController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/auth/register",
     *     tags={"Authentication"},
     *     summary="Register a new user",
     *     operationId="authRegister",
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name","email","password","password_confirmation"},
     *
     *             @OA\Property(property="name", type="string", example="Jane Doe"),
     *             @OA\Property(property="email", type="string", format="email", example="jane@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="secret123"),
     *             @OA\Property(property="password_confirmation", type="string", format="password", example="secret123")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="User registered",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="user", type="object"),
     *                 @OA\Property(property="token", type="string", example="1|sanctum-token")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error")
     * )
     * Register a new user.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password, // Auto-hashed via 'hashed' cast
        ]);

        // Note: email_verified_at is null - email verification handled in Story 3.4
        // User will need to verify email before accessing protected features
        $user->sendEmailVerificationNotification();

        // Create Sanctum token with configurable expiration
        $token = $this->createAuthToken($user);

        // Log successful registration for security audit
        Log::info('User registered', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
            ],
        ], 201);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/login",
     *     tags={"Authentication"},
     *     summary="Login and receive Sanctum token",
     *     operationId="authLogin",
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"email","password"},
     *
     *             @OA\Property(property="email", type="string", format="email", example="jane@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="secret123")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Login successful",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="user", type="object"),
     *                 @OA\Property(property="token", type="string", example="2|sanctum-token")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=422, description="Invalid credentials or validation error")
     * )
     * Login an existing user.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        // Generic error message to prevent email enumeration
        if (! $user || ! Hash::check($request->password, $user->password)) {
            // Log failed login attempt for security monitoring
            Log::warning('Failed login attempt', [
                'email' => $request->email,
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        // Create Sanctum token with configurable expiration
        $token = $this->createAuthToken($user);

        // Log successful login for security audit
        Log::info('User logged in', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
            ],
        ], 200);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/logout",
     *     tags={"Authentication"},
     *     summary="Logout current device",
     *     operationId="authLogout",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Logout successful",
     *
     *         @OA\JsonContent(@OA\Property(property="message", type="string", example="Logged out successfully."))
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     * Logout (revoke current token only).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        // Log logout for security audit
        Log::info('User logged out', [
            'user_id' => $request->user()->id,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Logged out successfully.',
        ], 200);
    }

    /**
     * Logout from all devices (revoke all tokens).
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $tokenCount = $request->user()->tokens()->count();
        $request->user()->tokens()->delete();

        // Log logout all for security audit
        Log::info('User logged out from all devices', [
            'user_id' => $request->user()->id,
            'tokens_revoked' => $tokenCount,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Logged out from all devices successfully.',
        ], 200);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/forgot-password",
     *     tags={"Authentication"},
     *     summary="Request a password reset link",
     *     operationId="authForgotPassword",
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"email"},
     *
     *             @OA\Property(property="email", type="string", format="email", example="jane@example.com")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Request accepted (generic response to prevent enumeration)",
     *
     *         @OA\JsonContent(@OA\Property(property="message", type="string"))
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error")
     * )
     * Send password reset link via email.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->email;
        $user = User::where('email', $email)->first();

        // Always return success to prevent email enumeration
        // But only send email if user exists
        if ($user) {
            // Delete any existing reset tokens for this email
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            // Generate a new token
            $token = Str::random(64);

            // Store hashed token in database
            DB::table('password_reset_tokens')->insert([
                'email' => $email,
                'token' => Hash::make($token),
                'created_at' => now(),
            ]);

            // Send notification with plain token (queued)
            $user->notify(new ResetPasswordNotification($token, $email));

            // Log password reset request for security audit
            Log::info('Password reset requested', [
                'email' => $email,
                'ip' => $request->ip(),
            ]);
        } else {
            // Log attempt with non-existent email
            Log::warning('Password reset attempted for non-existent email', [
                'email' => $email,
                'ip' => $request->ip(),
            ]);
        }

        return response()->json([
            'message' => 'If your email is registered, you will receive a password reset link.',
        ], 200);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/reset-password",
     *     tags={"Authentication"},
     *     summary="Reset password with reset token",
     *     operationId="authResetPassword",
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"email","token","password","password_confirmation"},
     *
     *             @OA\Property(property="email", type="string", format="email", example="jane@example.com"),
     *             @OA\Property(property="token", type="string", example="plain-reset-token"),
     *             @OA\Property(property="password", type="string", format="password", example="newSecret123"),
     *             @OA\Property(property="password_confirmation", type="string", format="password", example="newSecret123")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Password reset successful",
     *
     *         @OA\JsonContent(@OA\Property(property="message", type="string"))
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error or invalid/expired token")
     * )
     * Reset password using token.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $email = $request->email;
        $token = $request->token;
        $password = $request->password;

        // Find the reset record
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if (! $resetRecord) {
            throw ValidationException::withMessages([
                'email' => ['Invalid or expired reset token.'],
            ]);
        }

        // Check if token is expired
        $expirationMinutes = config('auth.passwords.users.expire', 60);
        $tokenCreatedAt = \Carbon\Carbon::parse($resetRecord->created_at);

        if ($tokenCreatedAt->addMinutes($expirationMinutes)->isPast()) {
            // Delete expired token
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            Log::warning('Password reset with expired token', [
                'email' => $email,
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'token' => ['This password reset link has expired.'],
            ]);
        }

        // Verify token matches
        if (! Hash::check($token, $resetRecord->token)) {
            Log::warning('Password reset with invalid token', [
                'email' => $email,
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([
                'token' => ['Invalid or expired reset token.'],
            ]);
        }

        // Find the user
        $user = User::where('email', $email)->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['Invalid or expired reset token.'],
            ]);
        }

        // Update password
        $user->password = $password; // Auto-hashed via 'hashed' cast
        $user->save();

        // Delete the reset token
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        // Revoke all existing Sanctum tokens (force re-login on all devices)
        $tokenCount = $user->tokens()->count();
        $user->tokens()->delete();

        // Log successful password reset for security audit
        Log::info('Password reset successfully', [
            'user_id' => $user->id,
            'email' => $email,
            'tokens_revoked' => $tokenCount,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Password has been reset successfully. Please login with your new password.',
        ], 200);
    }

    /**
     * Create authentication token with configurable expiration.
     */
    private function createAuthToken(User $user): string
    {
        $expirationMinutes = config('sanctum.expiration', 1440);

        return $user->createToken(
            'auth-token',
            ['*'],
            now()->addMinutes($expirationMinutes)
        )->plainTextToken;
    }
}
