<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class AuditLogPolicy extends BaseWorkspacePolicy
{
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $this->isAdmin($user, $workspace);
    }
}
