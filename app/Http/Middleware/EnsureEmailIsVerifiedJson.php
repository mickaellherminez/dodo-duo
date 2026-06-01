<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerifiedJson
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email not verified.',
                'action' => [
                    'type' => 'resend_verification_email',
                    'endpoint' => '/api/v1/email/verification-notification',
                ],
            ], 403);
        }

        return $next($request);
    }
}
