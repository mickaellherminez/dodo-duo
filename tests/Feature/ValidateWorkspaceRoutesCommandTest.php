<?php

use App\Http\Middleware\RequireSuperAdmin;
use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('workspace:validate-routes command', function () {
    test('passes for current route configuration', function () {
        $exitCode = Artisan::call('workspace:validate-routes');

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toContain('All tenant-scoped routes have workspace middleware.');
    });

    test('detects newly added authenticated api v1 route family by default', function () {
        $prefix = 'api/v1/test-workspace-validate/'.Str::uuid();
        $uri = $prefix.'/unsafe-default';

        Route::middleware(['api', 'auth:sanctum'])->get($uri, fn () => response()->json(['ok' => true]));

        $exitCode = Artisan::call('workspace:validate-routes');
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Found tenant-scoped routes missing workspace middleware.')
            ->and($output)->toContain($uri);
    });

    test('reports violation for tenant route missing workspace middleware', function () {
        $prefix = 'api/v1/test-workspace-validate/'.Str::uuid();
        $uri = $prefix.'/unsafe';

        Route::middleware(['api', 'auth:sanctum'])->get($uri, fn () => response()->json(['ok' => true]));

        $exitCode = Artisan::call('workspace:validate-routes', ['--prefix' => $prefix]);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Found tenant-scoped routes missing workspace middleware.')
            ->and($output)->toContain($uri);
    });

    test('accepts route protected by SetCurrentWorkspace middleware', function () {
        $prefix = 'api/v1/test-workspace-validate/'.Str::uuid();
        $uri = $prefix.'/safe';

        Route::middleware(['api', 'auth:sanctum', SetCurrentWorkspace::class])->get($uri, fn () => response()->json(['ok' => true]));

        $exitCode = Artisan::call('workspace:validate-routes', ['--prefix' => $prefix]);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toContain('All tenant-scoped routes have workspace middleware.');
    });

    test('accepts route protected by super-admin escape hatch middleware', function () {
        $prefix = 'api/v1/admin/test-workspace-validate/'.Str::uuid();
        $uri = $prefix.'/admin-safe';

        Route::middleware(['api', 'auth:sanctum', RequireSuperAdmin::class])->get($uri, fn () => response()->json(['ok' => true]));

        $exitCode = Artisan::call('workspace:validate-routes', ['--prefix' => $prefix]);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toContain('All tenant-scoped routes have workspace middleware.');
    });

    test('reports violation for super-admin route outside admin prefix', function () {
        $prefix = 'api/v1/test-workspace-validate/'.Str::uuid();
        $uri = $prefix.'/unsafe-super-admin';

        Route::middleware(['api', 'auth:sanctum', RequireSuperAdmin::class])->get($uri, fn () => response()->json(['ok' => true]));

        $exitCode = Artisan::call('workspace:validate-routes', ['--prefix' => $prefix]);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Found tenant-scoped routes missing workspace middleware.')
            ->and($output)->toContain($uri);
    });
});
