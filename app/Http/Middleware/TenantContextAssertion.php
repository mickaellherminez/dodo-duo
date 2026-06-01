<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * TenantContextAssertion Middleware
 *
 * Ensures that workspace context is set for protected routes.
 * Throws exception if workspace context is missing on non-whitelisted routes.
 */
class TenantContextAssertion
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if route is whitelisted (doesn't need workspace context)
        if ($this->isWhitelisted($request)) {
            return $next($request);
        }

        // Verify workspace context is set
        if (current_workspace() === null) {
            throw new RuntimeException(
                'Workspace context not set for protected route: '.$request->path().
                '. Ensure SetCurrentWorkspace middleware runs before this middleware.'
            );
        }

        return $next($request);
    }

    /**
     * Check if the request path is whitelisted.
     */
    protected function isWhitelisted(Request $request): bool
    {
        $path = '/'.trim($request->path(), '/');
        $whitelist = config('workspace.context_assertion_whitelist', []);

        foreach ($whitelist as $pattern) {
            // Normalize pattern
            $pattern = '/'.trim($pattern, '/');

            // Exact match
            if ($path === $pattern) {
                return true;
            }

            // Wildcard match (e.g., /public/*)
            if (str_contains($pattern, '*')) {
                $regex = '#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).'$#';
                if (preg_match($regex, $path)) {
                    return true;
                }
            }
        }

        return false;
    }
}
