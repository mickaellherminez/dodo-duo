<?php

use Illuminate\Support\Str;

describe('Pest bootstrap foundation', function () {
    test('custom expectations and datasets are available in unit tests', function (string $role) {
        expect($role)->toBeWorkspaceRole();
        expect((string) Str::uuid())->toBeUuid();
    })->with('workspace_roles');
});
