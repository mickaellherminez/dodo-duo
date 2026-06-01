<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy extends BaseWorkspacePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true; // All authenticated users can list workspaces
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Workspace $workspace): bool
    {
        if ($this->workspace() !== null && (int) $this->workspace()->id !== (int) $workspace->id) {
            return false;
        }

        return $this->isMember($user, $workspace);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true; // All authenticated users can create workspaces
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Workspace $workspace): bool
    {
        if ($this->workspace() !== null && (int) $this->workspace()->id !== (int) $workspace->id) {
            return false;
        }

        return $this->isAdmin($user, $workspace);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Workspace $workspace): bool
    {
        if ($this->workspace() !== null && (int) $this->workspace()->id !== (int) $workspace->id) {
            return false;
        }

        return $this->isOwner($user, $workspace);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Workspace $workspace): bool
    {
        if ($this->workspace() !== null && (int) $this->workspace()->id !== (int) $workspace->id) {
            return false;
        }

        return $this->isOwner($user, $workspace);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Workspace $workspace): bool
    {
        if ($this->workspace() !== null && (int) $this->workspace()->id !== (int) $workspace->id) {
            return false;
        }

        return $this->isOwner($user, $workspace);
    }
}
