<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    /**
     * Display the authenticated user's profile.
     */
    public function show(): JsonResponse
    {
        $user = auth()->user();

        return response()->json([
            'data' => new UserResource($user),
        ], 200);
    }

    /**
     * Update the authenticated user's profile.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $originalEmail = $user->email;

        $user->fill($request->only(['name', 'email']));

        if ($request->filled('email') && $request->email !== $originalEmail) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($request->filled('email') && $request->email !== $originalEmail) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'data' => new UserResource($user),
            'message' => 'Profile updated successfully.',
        ], 200);
    }

    /**
     * Change the authenticated user's password.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
            ], 400);
        }

        $user->password = $request->password; // Auto-hashed via 'hashed' cast
        $user->save();

        return response()->json([
            'message' => 'Password updated successfully.',
        ], 200);
    }
}
