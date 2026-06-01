<?php

declare(strict_types=1);

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('boots successfully', function () {
    expect(app())->toBeInstanceOf(\Illuminate\Foundation\Application::class);
    expect(app()->version())->toContain('11.');
});

it('has required directories', function () {
    expect(base_path('app'))->toBeDirectory();
    expect(base_path('config'))->toBeDirectory();
    expect(base_path('database'))->toBeDirectory();
    expect(base_path('routes'))->toBeDirectory();
    expect(base_path('tests'))->toBeDirectory();
});

it('meets php version requirement', function () {
    expect(PHP_VERSION_ID)->toBeGreaterThanOrEqual(80200); // PHP 8.2.0
});

it('has core dependencies installed', function () {
    expect(class_exists(\Laravel\Sanctum\Sanctum::class))->toBeTrue();
    expect(class_exists(\Laravel\Socialite\Facades\Socialite::class))->toBeTrue();
});

it('loads environment configuration', function () {
    expect(config('app.name'))->not->toBeEmpty();
    expect(config('app.key'))->not->toBeEmpty();
    // In testing environment, database.default is 'sqlite', in production it's 'mysql'
    expect(config('database.default'))->toBeIn(['mysql', 'sqlite']);
});

it('has pest php testing framework available', function () {
    expect(class_exists(\Pest\TestSuite::class))->toBeTrue();
});

it('has laravel pint code formatter available', function () {
    expect(file_exists(base_path('vendor/bin/pint')))->toBeTrue();
    expect(file_exists(base_path('pint.json')))->toBeTrue();
});

it('has editorconfig file for ide consistency', function () {
    expect(file_exists(base_path('.editorconfig')))->toBeTrue();
});

it('has proper composer json configuration', function () {
    $composerJson = json_decode(file_get_contents(base_path('composer.json')), true);

    expect($composerJson)->toHaveKey('require');
    expect($composerJson['require'])->toHaveKey('php');
    expect($composerJson['require']['php'])->toContain('8.2');
    expect($composerJson['require'])->toHaveKey('laravel/framework');
    expect($composerJson['require'])->toHaveKey('laravel/sanctum');

    expect($composerJson)->toHaveKey('require-dev');
    expect($composerJson['require-dev'])->toHaveKey('laravel/pint');
    expect($composerJson['require-dev'])->toHaveKey('pestphp/pest');
});

it('has sanctum configuration published', function () {
    expect(file_exists(config_path('sanctum.php')))->toBeTrue();

    // Check for any personal_access_tokens table migration file
    $migrations = glob(database_path('migrations/*_create_personal_access_tokens_table.php'));
    expect(count($migrations))->toBeGreaterThan(0);
});

it('has proper env example file with saasforge configuration', function () {
    $envExample = file_get_contents(base_path('.env.example'));

    expect($envExample)->toContain('APP_NAME=SaaSForge');
    expect($envExample)->toContain('DB_CONNECTION=mysql');
    expect($envExample)->toContain('CACHE_STORE=file');
    expect($envExample)->toContain('QUEUE_CONNECTION=database');
    expect($envExample)->toContain('SESSION_DRIVER=database');
    expect($envExample)->toContain('WORKSPACE_APP_DOMAIN');
    expect($envExample)->toContain('GOOGLE_CLIENT_ID');
    expect($envExample)->toContain('GITHUB_CLIENT_ID');
});
