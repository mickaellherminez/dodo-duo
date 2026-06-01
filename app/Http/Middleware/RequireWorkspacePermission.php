<?php

namespace App\Http\Middleware;

use App\Services\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireWorkspacePermission
{
    /**
     * Handle an incoming request.
     * Usage: route()->middleware('permission:resources.create')
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! app()->bound(CurrentWorkspace::class)) {
            return response()->json(
                ['message' => 'No workspace context set'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $workspace = app(CurrentWorkspace::class)->workspace;
        $user = $request->user();

        if (! $user?->canInWorkspace($permission, $workspace)) {
            abort(Response::HTTP_FORBIDDEN, 'Insufficient permissions for this workspace');
        }

        return $next($request);
    }
}
