<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

describe('Shared Hosting Optimization', function () {

    describe('File Cache', function () {
        afterEach(function () {
            Cache::store('file')->forget('test_shared_hosting_key');
        });

        it('file cache store supports set get forget operations', function () {
            $store = Cache::store('file');

            $store->put('test_shared_hosting_key', 'test_value', 60);

            expect($store->get('test_shared_hosting_key'))->toBe('test_value');

            $store->forget('test_shared_hosting_key');

            expect($store->get('test_shared_hosting_key'))->toBeNull();
        });

        it('file cache store is available and configured', function () {
            expect(config('cache.stores.file'))->toBeArray();
            expect(config('cache.stores.file.driver'))->toBe('file');
        });
    });

    describe('Database Queue', function () {
        uses(RefreshDatabase::class);

        it('jobs table is accessible', function () {
            expect(DB::table('jobs')->count())->toBeGreaterThanOrEqual(0);
        });

        it('failed_jobs table is accessible', function () {
            expect(DB::table('failed_jobs')->count())->toBeGreaterThanOrEqual(0);
        });

        it('database queue connection is configured', function () {
            expect(config('queue.connections.database'))->toBeArray();
            expect(config('queue.connections.database.driver'))->toBe('database');
        });
    });

    describe('deploy:prod Command', function () {
        afterEach(function () {
            // deploy:prod writes config/route/view caches with test env values (sqlite).
            // Clear them so the real server keeps using .env (mysql).
            \Illuminate\Support\Facades\Artisan::call('config:clear');
            \Illuminate\Support\Facades\Artisan::call('route:clear');
            \Illuminate\Support\Facades\Artisan::call('view:clear');
        });

        it('deploy:prod command runs and exits successfully with expected output', function () {
            $this->artisan('deploy:prod')
                ->expectsOutputToContain('Deploying to production')
                ->expectsOutputToContain('Clearing application cache')
                ->expectsOutputToContain('Deployment complete')
                ->assertExitCode(0);
        });

        it('deploy:prod command is registered in artisan', function () {
            $this->artisan('list')
                ->expectsOutputToContain('deploy:prod')
                ->assertSuccessful();
        });
    });

});
