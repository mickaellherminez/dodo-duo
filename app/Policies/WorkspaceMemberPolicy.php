<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

class WorkspaceMemberPolicy extends BaseWorkspacePolicy
{
    /**
     * Any active workspace member can view the member list.
     */
    public function viewAny(User $user, Workspace $workspace): bool
    {
        if ($this->workspace() !== null && (int) $this->workspace()->id !== (int) $workspace->id) {
            return false;
        }

        return $this->isMember($user, $workspace);
    }

    /**
     * Workspace owner or admin can update member roles.
     * (Admin role restrictions are enforced in the controller.)
     */
    public function update(User $user, WorkspaceMember $member): bool
    {
        if ($this->workspace() !== null && ! $this->belongsToCurrentWorkspace($member)) {
            return false;
        }

        return $this->isAdmin($user, $member->workspace);
    }

    /**
     * Only workspace owner can remove members.
     */
    public function delete(User $user, WorkspaceMember $member): bool
    {
        if ($this->workspace() !== null && ! $this->belongsToCurrentWorkspace($member)) {
            return false;
        }

        return $this->isOwner($user, $member->workspace);
    }
}
