<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    /**
     * Verify the user's email address using signed URL.
     */
    public function verify(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return ApiResponse::error('Resource not found.', 404);
        }

        if (! hash_equals(sha1($user->getEmailForVerification()), (string) $hash)) {
            return ApiResponse::error('This action is unauthorized.', 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified.',
            ], 200);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json([
            'message' => 'Email verified successfully.',
        ], 200);
    }

    /**
     * Resend the email verification notification.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified.',
            ], 200);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Verification email sent.',
        ], 200);
    }
}
