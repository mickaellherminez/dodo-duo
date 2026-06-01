<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Services\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SetCurrentWorkspace Middleware
 *
 * Resolves and sets the current workspace context for the request.
 * Tries multiple resolution strategies in priority order.
 */
class SetCurrentWorkspace
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $this->resolveWorkspace($request);

        if (! $workspace) {
            // No workspace resolved - continue without setting context
            // TenantContextAssertion middleware will enforce if workspace is required
            return $next($request);
        }

        // Verify user has access to this workspace (if enabled)
        if (config('workspace.verify_user_access', true)) {
            if (! $this->userHasAccess($request, $workspace)) {
                abort(403, 'You do not have access to this workspace.');
            }
        }

        // Bind workspace to service container
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));

        return $next($request);
    }

    /**
     * Resolve workspace using configured strategies.
     */
    protected function resolveWorkspace(Request $request): ?Workspace
    {
        $strategies = config('workspace.resolution_strategy_priority', ['subdomain', 'domain', 'header', 'route']);

        foreach ($strategies as $strategy) {
            $result = match ($strategy) {
                'subdomain' => $this->resolveFromSubdomain($request),
                'domain' => $this->resolveFromDomain($request),
                'header' => $this->resolveFromHeader($request),
                'route' => $this->resolveFromRoute($request),
                'token' => $this->resolveFromToken($request),
                default => ['strategy' => null, 'workspace' => null],
            };

            // If strategy was applicable but workspace not found, abort
            if (is_array($result)) {
                if ($result['strategy'] === 'attempted' && $result['workspace'] === null) {
                    abort(404, 'Workspace not found.');
                }
                if ($result['workspace']) {
                    return $result['workspace'];
                }
            } elseif ($result) {
                // Backward compatibility: direct Workspace return
                return $result;
            }
        }

        return null;
    }

    /**
     * Resolve workspace from subdomain.
     * Example: acme.saasforge.app → find workspace by slug 'acme'
     *
     * Returns: ['strategy' => 'attempted', 'workspace' => ?Workspace]
     * or ['strategy' => null, 'workspace' => null] if subdomain strategy not applicable
     */
    protected function resolveFromSubdomain(Request $request): array
    {
        $host = $request->getHost();
        $appDomain = config('workspace.app_domain', 'localhost');

        // Extract subdomain
        if (! str_ends_with($host, $appDomain)) {
            return ['strategy' => null, 'workspace' => null];
        }

        $subdomain = str_replace('.'.$appDomain, '', $host);

        // Ignore www and empty subdomain
        if (in_array($subdomain, ['', 'www', $appDomain])) {
            return ['strategy' => null, 'workspace' => null];
        }

        // Subdomain present - strategy is being attempted
        $workspace = Workspace::where('slug', $subdomain)
            ->where('status', 'active')
            ->first();

        return ['strategy' => 'attempted', 'workspace' => $workspace];
    }

    /**
     * Resolve workspace from custom domain.
     * Example: app.acme.com → find workspace by domain
     *
     * Returns: ['strategy' => 'attempted', 'workspace' => ?Workspace]
     * or ['strategy' => null, 'workspace' => null] if domain doesn't match app_domain
     */
    protected function resolveFromDomain(Request $request): array
    {
        $host = $request->getHost();
        $appDomain = config('workspace.app_domain', 'localhost');

        // If host matches app_domain, this strategy doesn't apply
        if ($host === $appDomain || str_ends_with($host, '.'.$appDomain)) {
            return ['strategy' => null, 'workspace' => null];
        }

        // Ignore localhost/127.0.0.1 (test environments)
        if (in_array($host, ['localhost', '127.0.0.1', '::1'])) {
            return ['strategy' => null, 'workspace' => null];
        }

        // Custom domain strategy is being attempted
        $workspace = Workspace::where('domain', $host)
            ->where('status', 'active')
            ->first();

        return ['strategy' => 'attempted', 'workspace' => $workspace];
    }

    /**
     * Resolve workspace from X-Workspace-ID header.
     * Example: X-Workspace-ID: 123
     *
     * Returns: ['strategy' => 'attempted', 'workspace' => ?Workspace]
     * or ['strategy' => null, 'workspace' => null] if header not present
     */
    protected function resolveFromHeader(Request $request): array
    {
        $workspaceId = $request->header('X-Workspace-ID');

        if (! $workspaceId) {
            return ['strategy' => null, 'workspace' => null];
        }

        // Header present - strategy is being attempted
        $workspace = Workspace::where('id', $workspaceId)
            ->where('status', 'active')
            ->first();

        return ['strategy' => 'attempted', 'workspace' => $workspace];
    }

    /**
     * Resolve workspace from route parameter.
     * Example: /api/workspaces/{workspace}/projects
     *
     * Returns: ['strategy' => 'attempted', 'workspace' => ?Workspace]
     * or ['strategy' => null, 'workspace' => null] if route parameter not present
     */
    protected function resolveFromRoute(Request $request): array
    {
        $workspaceParam = $request->route('workspace');

        if (! $workspaceParam) {
            return ['strategy' => null, 'workspace' => null];
        }

        // Route parameter present - strategy is being attempted
        // Check if already a Workspace model (route model binding)
        if ($workspaceParam instanceof Workspace) {
            return ['strategy' => 'attempted', 'workspace' => $workspaceParam->status === 'active' ? $workspaceParam : null];
        }

        // Support both slug and ID (string/int)
        if (is_numeric($workspaceParam)) {
            $workspace = Workspace::where('id', $workspaceParam)
                ->where('status', 'active')
                ->first();
        } else {
            $workspace = Workspace::where('slug', $workspaceParam)
                ->where('status', 'active')
                ->first();
        }

        return ['strategy' => 'attempted', 'workspace' => $workspace];
    }

    /**
     * Resolve workspace from Sanctum token abilities.
     * Token should have ability pattern: workspace:{id}
     *
     * Returns: ['strategy' => null, 'workspace' => ?Workspace]
     * Token strategy is always optional - never aborts if not found
     */
    protected function resolveFromToken(Request $request): array
    {
        $user = $request->user();

        if (! $user || ! $user->currentAccessToken()) {
            return ['strategy' => null, 'workspace' => null];
        }

        // Get token abilities
        $abilities = $user->currentAccessToken()->abilities ?? [];

        // Look for workspace:{id} pattern in abilities
        foreach ($abilities as $ability) {
            if (preg_match('/^workspace:(\d+)$/', $ability, $matches)) {
                $workspaceId = (int) $matches[1];

                $workspace = Workspace::where('id', $workspaceId)
                    ->where('status', 'active')
                    ->first();

                // Token strategy is optional - return null strategy even if workspace found
                // This ensures we don't abort if token has invalid workspace ID
                return ['strategy' => null, 'workspace' => $workspace];
            }
        }

        return ['strategy' => null, 'workspace' => null];
    }

    /**
     * Check if authenticated user has access to workspace.
     */
    protected function userHasAccess(Request $request, Workspace $workspace): bool
    {
        $user = $request->user();

        if (! $user) {
            // No user authenticated - deny access
            // TODO: In future, check if workspace is public
            return false;
        }

        // Check if user is the workspace owner
        if ($workspace->owner_id === $user->id) {
            return true;
        }

        // Check if user is a member of the workspace
        return $user->belongsToWorkspace($workspace);
    }

    /**
     * Handle workspace not found scenario.
     */
    protected function handleWorkspaceNotFound(): Response
    {
        $behavior = config('workspace.not_found_behavior', 'abort_404');

        return match ($behavior) {
            'abort_403' => abort(403, 'Workspace access denied.'),
            'abort_404' => abort(404, 'Resource not found.'),
            default => abort(404, 'Resource not found.'),
        };
    }
}
