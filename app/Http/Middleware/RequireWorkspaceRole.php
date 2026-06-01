<?php

namespace App\Http\Middleware;

use App\Services\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireWorkspaceRole
{
    /**
     * Handle an incoming request.
     * Usage: route()->middleware('role:owner') or 'role:owner,admin'
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! app()->bound(CurrentWorkspace::class)) {
            return response()->json(
                ['message' => 'No workspace context set'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $workspace = app(CurrentWorkspace::class)->workspace;
        $user = $request->user();

        if (! $user) {
            abort(Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        }

        $userRole = $user->getWorkspaceRole($workspace);

        if ($userRole === null || ! in_array($userRole->value, $roles, true)) {
            abort(Response::HTTP_FORBIDDEN, 'Insufficient permissions for this workspace');
        }

        return $next($request);
    }
}
