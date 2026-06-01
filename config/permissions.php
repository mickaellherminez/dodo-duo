<?php

return [
    'owner' => ['*'],

    'admin' => [
        'workspace.view',
        'workspace.update',
        'members.view',
        'members.invite',
        'members.remove',
        'members.update-role',
        'resources.*',
    ],

    'member' => [
        'workspace.view',
        'members.view',
        'resources.view',
        'resources.create',
        'resources.update-own',
        'resources.delete-own',
    ],

    'guest' => [
        'workspace.view',
        'members.view',
        'resources.view',
    ],
];
