<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('API response standards', function () {
    test('validation errors use standardized JSON format (422)', function () {
        $response = $this->postJson('/api/v1/auth/register', []);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'message',
                'errors',
            ])
            ->assertJsonPath('errors.name.0', fn (string $value) => $value !== '');
    });

    test('unauthenticated errors use standardized JSON format (401)', function () {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401)
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);
    });

    test('forbidden errors use standardized JSON format (403)', function () {
        $uri = 'api/v1/test-response-standards/'.Str::uuid().'/forbidden';

        Route::middleware('api')->get($uri, function () {
            abort(403, 'Custom forbidden message');
        });

        $response = $this->getJson('/'.$uri);

        $response->assertStatus(403)
            ->assertExactJson([
                'message' => 'This action is unauthorized.',
            ]);
    });

    test('not found errors use standardized JSON format (404)', function () {
        $response = $this->getJson('/api/v1/test-response-standards/'.Str::uuid().'/missing');

        $response->assertStatus(404)
            ->assertExactJson([
                'message' => 'Resource not found.',
            ]);
    });

    test('server errors hide internal details in non-debug mode', function () {
        config(['app.debug' => false]);

        $uri = 'api/v1/test-response-standards/'.Str::uuid().'/boom';

        Route::middleware('api')->get($uri, function () {
            throw new RuntimeException('Internal failure details');
        });

        $response = $this->getJson('/'.$uri);

        $response->assertStatus(500)
            ->assertExactJson([
                'message' => 'Server Error',
            ]);
    });

    test('server errors include debug field only in debug mode', function () {
        config(['app.debug' => true]);

        $uri = 'api/v1/test-response-standards/'.Str::uuid().'/boom-debug';

        Route::middleware('api')->get($uri, function () {
            throw new RuntimeException('Internal failure details');
        });

        $response = $this->getJson('/'.$uri);

        $response->assertStatus(500)
            ->assertJson([
                'message' => 'Server Error',
                'error' => 'Internal failure details',
            ])
            ->assertJsonMissingPath('errors');
    });
});
