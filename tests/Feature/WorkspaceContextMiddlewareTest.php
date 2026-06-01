<?php

use App\Http\Middleware\SetCurrentWorkspace;
use App\Http\Middleware\TenantContextAssertion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Workspace Context Middleware Tests
 *
 * Tests for SetCurrentWorkspace and TenantContextAssertion middlewares.
 * Story 1.5 - Workspace Context Middleware Stack
 */
describe('SetCurrentWorkspace - Subdomain Resolution', function () {
    beforeEach(function () {
        Config::set('workspace.app_domain', 'saasforge.test');
        Config::set('workspace.verify_user_access', false); // Disable access check for resolution tests

        $this->workspace = Workspace::factory()->create(['slug' => 'acme']);
        $this->middleware = new SetCurrentWorkspace;
    });

    test('resolves workspace from subdomain', function () {
        $request = Request::create('http://acme.saasforge.test/dashboard');

        $this->middleware->handle($request, fn () => response('OK'));

        expect(current_workspace())->not->toBeNull()
            ->and(current_workspace()->id())->toBe($this->workspace->id)
            ->and(current_workspace()->slug())->toBe('acme');
    });

    test('ignores www subdomain', function () {
        $request = Request::create('http://www.saasforge.test/');

        $this->middleware->handle($request, fn () => response('OK'));

        expect(current_workspace())->toBeNull();
    });

    test('ignores empty subdomain', function () {
        $request = Request::create('http://saasforge.test/');

        $this->middleware->handle($request, fn () => response('OK'));

        expect(current_workspace())->toBeNull();
    });

    test('returns 404 when workspace slug not found', function () {
        $request = Request::create('http://nonexistent.saasforge.test/');

        expect(fn () => $this->middleware->handle($request, fn () => response('OK')))
            ->toThrow(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
    });

    test('ignores suspended workspace', function () {
        $suspended = Workspace::factory()->suspended()->create(['slug' => 'suspended']);
        $request = Request::create('http://suspended.saasforge.test/');

        expect(fn () => $this->middleware->handle($request, fn () => response('OK')))
            ->toThrow(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
    });
});

describe('SetCurrentWorkspace - Custom Domain Resolution', function () {
    beforeEach(function () {
        Config::set('workspace.verify_user_access', false);
        Config::set('workspace.app_domain', 'saasforge.test'); // Set different from custom domain

        $this->workspace = Workspace::factory()->withDomain('app.acme.com')->create();
        $this->middleware = new SetCurrentWorkspace;
    });

    test('resolves workspace from custom domain', function () {
        $request = Request::create('http://app.acme.com/dashboard');

        $this->middleware->handle($request, fn () => response('OK'));

        expect(current_workspace())->not->toBeNull()
            ->and(current_workspace()->id())->toBe($this->workspace->id)
            ->and(current_workspace()->domain())->toBe('app.acme.com');
    });

    test('returns 404 when custom domain not found', function () {
        $request = Request::create('http://unknown.com/');

        expect(fn () => $this->middleware->handle($request, fn () => response('OK')))
            ->toThrow(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
    });
});

describe('SetCurrentWorkspace - Header Resolution', function () {
    beforeEach(function () {
        Config::set('workspace.app_domain', 'localhost');
        Config::set('workspace.verify_user_access', false);

        $this->workspace = Workspace::factory()->create();
        $this->middleware = new SetCurrentWorkspace;
    });

    test('resolves workspace from X-Workspace-ID header', function () {
        $request = Request::create('http://localhost/api/projects');
        $request->headers->set('X-Workspace-ID', $this->workspace->id);

        $this->middleware->handle($request, fn () => response('OK'));

        expect(current_workspace())->not->toBeNull()
            ->and(current_workspace()->id())->toBe($this->workspace->id);
    });

    test('returns 404 when header workspace ID not found', function () {
        $request = Request::create('http://localhost/api/projects');
        $request->headers->set('X-Workspace-ID', 99999);

        expect(fn () => $this->middleware->handle($request, fn () => response('OK')))
            ->toThrow(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
    });
});

describe('SetCurrentWorkspace - Route Parameter Resolution', function () {
    beforeEach(function () {
        Config::set('workspace.app_domain', 'localhost');
        Config::set('workspace.verify_user_access', false);

        $this->workspace = Workspace::factory()->create(['slug' => 'acme']);
        $this->middleware = new SetCurrentWorkspace;
    });

    test('resolves workspace from route parameter by slug', function () {
        Route::get('/workspaces/{workspace}/projects', function () {
            return 'OK';
        });

        $request = Request::create('http://localhost/workspaces/acme/projects');
        $request->setRouteResolver(function () use ($request) {
            $route = Route::getRoutes()->match($request);
            $route->bind($request);

            return $route;
        });

        $this->middleware->handle($request, fn () => response('OK'));

        expect(current_workspace())->not->toBeNull()
            ->and(current_workspace()->slug())->toBe('acme');
    });

    test('resolves workspace from route parameter by ID', function () {
        Route::get('/workspaces/{workspace}/projects', function () {
            return 'OK';
        });

        $request = Request::create('http://localhost/workspaces/'.$this->workspace->id.'/projects');
        $request->setRouteResolver(function () use ($request) {
            $route = Route::getRoutes()->match($request);
            $route->bind($request);

            return $route;
        });

        $this->middleware->handle($request, fn () => response('OK'));

        expect(current_workspace())->not->toBeNull()
            ->and(current_workspace()->id())->toBe($this->workspace->id);
    });
});

describe('SetCurrentWorkspace - User Access Verification', function () {
    beforeEach(function () {
        Config::set('workspace.verify_user_access', true);
        Config::set('workspace.app_domain', 'saasforge.test');

        $this->owner = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->workspace = Workspace::factory()->create([
            'slug' => 'acme',
            'owner_id' => $this->owner->id,
        ]);
        $this->middleware = new SetCurrentWorkspace;
    });

    test('allows workspace owner to access workspace', function () {
        $request = Request::create('http://acme.saasforge.test/dashboard');
        $request->setUserResolver(fn () => $this->owner);

        $response = $this->middleware->handle($request, fn () => response('OK'));

        expect($response->status())->toBe(200)
            ->and(current_workspace())->not->toBeNull();
    });

    test('denies access to non-owner user', function () {
        $request = Request::create('http://acme.saasforge.test/dashboard');
        $request->setUserResolver(fn () => $this->otherUser);

        expect(fn () => $this->middleware->handle($request, fn () => response('OK')))
            ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    });

    test('denies access to unauthenticated user', function () {
        $request = Request::create('http://acme.saasforge.test/dashboard');
        $request->setUserResolver(fn () => null);

        expect(fn () => $this->middleware->handle($request, fn () => response('OK')))
            ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    });
});

describe('SetCurrentWorkspace - Resolution Strategy Priority', function () {
    beforeEach(function () {
        Config::set('workspace.verify_user_access', false);

        $this->subdomainWorkspace = Workspace::factory()->create(['slug' => 'subdomain']);
        $this->domainWorkspace = Workspace::factory()->withDomain('custom.com')->create();
        $this->middleware = new SetCurrentWorkspace;
    });

    test('subdomain takes priority over custom domain when both match', function () {
        Config::set('workspace.app_domain', 'custom.com');
        Config::set('workspace.resolution_strategy_priority', ['subdomain', 'domain']);

        $request = Request::create('http://subdomain.custom.com/');

        $this->middleware->handle($request, fn () => response('OK'));

        // Should resolve subdomain workspace, not domain workspace
        expect(current_workspace()->slug())->toBe('subdomain');
    });

    test('custom strategy priority order is respected', function () {
        Config::set('workspace.resolution_strategy_priority', ['header', 'subdomain']);
        Config::set('workspace.app_domain', 'saasforge.test');

        $headerWorkspace = Workspace::factory()->create();
        $request = Request::create('http://subdomain.saasforge.test/');
        $request->headers->set('X-Workspace-ID', $headerWorkspace->id);

        $this->middleware->handle($request, fn () => response('OK'));

        // Header should win because it's first in priority
        expect(current_workspace()->id())->toBe($headerWorkspace->id);
    });
});

describe('TenantContextAssertion - Whitelist Behavior', function () {
    beforeEach(function () {
        $this->middleware = new TenantContextAssertion;
    });

    test('allows whitelisted exact path without workspace context', function () {
        Config::set('workspace.context_assertion_whitelist', ['/login', '/register']);

        $request = Request::create('http://localhost/login');

        $response = $this->middleware->handle($request, fn () => response('OK'));

        expect($response->status())->toBe(200);
    });

    test('allows whitelisted wildcard path without workspace context', function () {
        Config::set('workspace.context_assertion_whitelist', ['/public/*']);

        $request = Request::create('http://localhost/public/images/logo.png');

        $response = $this->middleware->handle($request, fn () => response('OK'));

        expect($response->status())->toBe(200);
    });

    test('throws exception on non-whitelisted path without workspace context', function () {
        Config::set('workspace.context_assertion_whitelist', ['/login']);

        $request = Request::create('http://localhost/dashboard');

        $this->middleware->handle($request, fn () => response('OK'));
    })->throws(RuntimeException::class, 'Workspace context not set for protected route');

    test('allows non-whitelisted path when workspace context is set', function () {
        Config::set('workspace.context_assertion_whitelist', ['/login']);

        $workspace = Workspace::factory()->create();
        app()->instance(CurrentWorkspace::class, new CurrentWorkspace($workspace));

        $request = Request::create('http://localhost/dashboard');

        $response = $this->middleware->handle($request, fn () => response('OK'));

        expect($response->status())->toBe(200);
    });
});

describe('TenantContextAssertion - Wildcard Matching', function () {
    beforeEach(function () {
        Config::set('workspace.context_assertion_whitelist', ['/api/public/*', '/docs/*']);
        $this->middleware = new TenantContextAssertion;
    });

    test('matches nested wildcard paths', function () {
        $request = Request::create('http://localhost/api/public/v1/health');

        $response = $this->middleware->handle($request, fn () => response('OK'));

        expect($response->status())->toBe(200);
    });

    test('does not match partial wildcard paths', function () {
        $request = Request::create('http://localhost/api/private/data');

        $this->middleware->handle($request, fn () => response('OK'));
    })->throws(RuntimeException::class);
});
