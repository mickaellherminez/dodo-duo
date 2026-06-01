<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case MEMBER = 'member';
    case GUEST = 'guest';

    private const HIERARCHY = [
        'owner' => 4,
        'admin' => 3,
        'member' => 2,
        'guest' => 1,
    ];

    /**
     * Returns the array of permission strings for this role.
     */
    public function permissions(): array
    {
        return config('permissions.'.$this->value, []);
    }

    /**
     * Check if this role has the given permission.
     * Supports '*' (all permissions) and 'prefix.*' (wildcard prefix).
     */
    public function can(string $permission): bool
    {
        foreach ($this->permissions() as $granted) {
            if ($granted === '*') {
                return true;
            }

            if (str_ends_with($granted, '.*')) {
                $prefix = substr($granted, 0, -2);
                if (str_starts_with($permission, $prefix.'.')) {
                    return true;
                }
            }

            if ($granted === $permission) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if this role is at least as powerful as the given role.
     * OWNER > ADMIN > MEMBER > GUEST
     */
    public function isAtLeast(WorkspaceRole $role): bool
    {
        return self::HIERARCHY[$this->value] >= self::HIERARCHY[$role->value];
    }
}
