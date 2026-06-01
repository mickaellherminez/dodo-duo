<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSuperAdmin
{
    /**
     * Protect admin escape-hatch routes using gate-backed authorization.
     *
     * Usage: route()->middleware('super_admin:viewAdminDashboard')
     */
    public function handle(Request $request, Closure $next, string $ability = 'manageAllWorkspaces'): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        }

        if (! $user->can($ability)) {
            abort(Response::HTTP_FORBIDDEN, 'Super-admin access required.');
        }

        return $next($request);
    }
}
