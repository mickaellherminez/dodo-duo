<?php

declare(strict_types=1);

use App\Models\Workspace;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)->in('Feature');
pest()->extend(Tests\TestCase::class)->in('Unit');
pest()->extend(Tests\Feature\Security\AdversarialTestCase::class)->in('Feature/Security');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeUuid', function () {
    return $this->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
});

expect()->extend('toHaveWorkspaceId', function (int $workspaceId) {
    $value = $this->value;

    $actual = match (true) {
        is_array($value) => $value['workspace_id'] ?? null,
        is_object($value) => $value->workspace_id ?? null,
        default => null,
    };

    expect($actual)->toBe($workspaceId);

    return $this;
});

expect()->extend('toBeWorkspaceRole', function () {
    expect(in_array($this->value, ['owner', 'admin', 'member', 'guest'], true))->toBeTrue();

    return $this;
});

/*
|--------------------------------------------------------------------------
| Datasets
|--------------------------------------------------------------------------
|
| Shared datasets reduce duplication across feature and unit tests.
|
*/

dataset('workspace_roles', ['owner', 'admin', 'member', 'guest']);
dataset('project_statuses', ['active', 'archived', 'completed']);

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| Small global helpers are acceptable when they stay simple and explicit.
|
*/

function workspace_header(Workspace|int $workspace): array
{
    $workspaceId = $workspace instanceof Workspace
        ? $workspace->id
        : $workspace;

    return ['X-Workspace-ID' => $workspaceId];
}
