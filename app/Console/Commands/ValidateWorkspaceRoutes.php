<?php

namespace App\Console\Commands;

use App\Http\Middleware\RequireSuperAdmin;
use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

class ValidateWorkspaceRoutes extends Command
{
    protected $signature = 'workspace:validate-routes {--prefix= : Restrict validation to a URI prefix (useful for tests/targeted checks)}';

    protected $description = 'Validate that tenant-scoped routes are protected by workspace middleware';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $violations = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (! $this->shouldValidate($route)) {
                continue;
            }

            if ($this->hasWorkspaceMiddleware($route) || $this->hasApprovedEscapeHatch($route)) {
                continue;
            }

            $action = $route->getAction();

            $violations[] = [
                'uri' => $route->uri(),
                'method' => implode('|', $route->methods()),
                'action' => $action['controller'] ?? 'Closure',
            ];
        }

        if ($violations === []) {
            $this->info('All tenant-scoped routes have workspace middleware.');

            return self::SUCCESS;
        }

        $this->error('Found tenant-scoped routes missing workspace middleware.');
        $this->table(['URI', 'Method', 'Action'], $violations);

        return self::FAILURE;
    }

    protected function shouldValidate(Route $route): bool
    {
        $uri = ltrim($route->uri(), '/');
        $prefix = $this->option('prefix');

        if (is_string($prefix) && $prefix !== '') {
            return str_starts_with($uri, ltrim($prefix, '/'));
        }

        if (! str_starts_with($uri, 'api/v1/')) {
            return false;
        }

        // Generic rule: validate authenticated API v1 routes by default, except known
        // authenticated routes that intentionally do not require workspace context.
        if (! $this->hasSanctumAuthMiddleware($route)) {
            return false;
        }

        return ! $this->isKnownNonTenantRoute($uri);
    }

    protected function hasWorkspaceMiddleware(Route $route): bool
    {
        $middlewares = $route->gatherMiddleware();

        foreach ($middlewares as $middleware) {
            // Support both alias-based and class-based registration.
            if ($middleware === 'workspace' || $middleware === SetCurrentWorkspace::class) {
                return true;
            }

            // Defensive support for parameterized alias syntax, if introduced later.
            if (str_starts_with($middleware, 'workspace:')) {
                return true;
            }
        }

        return false;
    }

    protected function hasApprovedEscapeHatch(Route $route): bool
    {
        $uri = ltrim($route->uri(), '/');

        // Restrict approved escape hatches to the explicit admin API namespace.
        // A super-admin middleware on a tenant route should not hide a missing
        // workspace middleware bug from this validator.
        if (! $this->isApprovedEscapeHatchUri($uri)) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if ($middleware === 'super_admin' || $middleware === RequireSuperAdmin::class) {
                return true;
            }

            if (str_starts_with($middleware, 'super_admin:')) {
                return true;
            }
        }

        return false;
    }

    protected function isApprovedEscapeHatchUri(string $uri): bool
    {
        return $uri === 'api/v1/admin'
            || str_starts_with($uri, 'api/v1/admin/');
    }

    protected function hasSanctumAuthMiddleware(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if ($middleware === 'auth:sanctum') {
                return true;
            }

            if (str_starts_with($middleware, 'auth:') && str_contains($middleware, 'sanctum')) {
                return true;
            }
        }

        return false;
    }

    protected function isKnownNonTenantRoute(string $uri): bool
    {
        return str_starts_with($uri, 'api/v1/auth/')
            || str_starts_with($uri, 'api/v1/invitations/')
            || $uri === 'api/v1/me'
            || str_starts_with($uri, 'api/v1/me/')
            || $uri === 'api/v1/email/verification-notification';
    }
}
