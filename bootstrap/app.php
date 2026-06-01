<?php

use App\Http\Middleware\EnsureEmailIsVerifiedJson;
use App\Http\Middleware\MeasureResponseTime;
use App\Http\Middleware\RequireSuperAdmin;
use App\Http\Middleware\RequireWorkspacePermission;
use App\Http\Middleware\RequireWorkspaceRole;
use App\Http\Middleware\SetCurrentWorkspace;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->appendToGroup('api', MeasureResponseTime::class);

        $middleware->alias([
            'verified' => EnsureEmailIsVerifiedJson::class,
            'role' => RequireWorkspaceRole::class,
            'permission' => RequireWorkspacePermission::class,
            'super_admin' => RequireSuperAdmin::class,
        ]);

        // Ensure SetCurrentWorkspace runs AFTER auth (so $request->user() is populated)
        // but BEFORE SubstituteBindings (route model binding), so WorkspaceScope can
        // filter queries by the resolved workspace ID during binding resolution.
        $middleware->priority([
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            SetCurrentWorkspace::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);

        // Pour les routes API, ne pas rediriger vers login mais retourner 401 JSON
        $middleware->redirectGuestsTo(function ($request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                abort(401, 'Unauthenticated.');
            }

            return route('login');
        });

        // Le contexte workspace est désormais appliqué directement sur les routes API
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $isApiRequest = static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error(
                message: $e->getMessage() ?: 'The given data was invalid.',
                status: 422,
                errors: $e->errors()
            );
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error('Unauthenticated.', 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error('This action is unauthorized.', 403);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            return ApiResponse::error('Resource not found.', 404);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            $status = $e->getStatusCode();

            $message = match ($status) {
                401 => 'Unauthenticated.',
                403 => 'This action is unauthorized.',
                404 => 'Resource not found.',
                default => $e->getMessage() ?: 'HTTP Error',
            };

            return ApiResponse::error($message, $status);
        });

        $exceptions->render(function (\Throwable $e, Request $request) use ($isApiRequest) {
            if (! $isApiRequest($request)) {
                return null;
            }

            if ($e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof AuthorizationException
                || $e instanceof ModelNotFoundException
                || $e instanceof HttpExceptionInterface
            ) {
                return null;
            }

            return ApiResponse::error(
                message: 'Server Error',
                status: 500,
                debugError: config('app.debug') ? $e->getMessage() : null
            );
        });
    })->create();
