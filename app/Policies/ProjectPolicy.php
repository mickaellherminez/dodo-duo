<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class ProjectPolicy extends BaseWorkspacePolicy
{
    private static ?bool $supportsOwnResourcePermissions = null;

    /**
     * Any workspace member can list projects in their current workspace.
     */
    public function viewAny(User $user): bool
    {
        return $this->isMember($user);
    }

    /**
     * Members can view projects from the current workspace only.
     */
    public function view(User $user, Project $project): bool
    {
        if (! $this->belongsToCurrentWorkspace($project)) {
            return false;
        }

        return $this->isMember($user);
    }

    /**
     * Creation follows role permission mapping from RBAC story.
     */
    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'resources.create');
    }

    /**
     * Update is restricted to admins/owners by default.
     * If created_by exists, member-level own-resource permission is also supported.
     */
    public function update(User $user, Project $project): bool
    {
        if (! $this->belongsToCurrentWorkspace($project)) {
            return false;
        }

        if ($this->isAdmin($user)) {
            return true;
        }

        if (! $this->supportsOwnResourcePermissions()) {
            return false;
        }

        $createdBy = $project->getAttribute('created_by');

        if ($createdBy === null) {
            return false;
        }

        return (int) $createdBy === (int) $user->id
            && $this->hasPermission($user, 'resources.update-own');
    }

    /**
     * Restore follows the same model as delete.
     */
    public function restore(User $user, Project $project): bool
    {
        if (! $this->belongsToCurrentWorkspace($project)) {
            return false;
        }

        if ($this->isAdmin($user)) {
            return true;
        }

        if (! $this->supportsOwnResourcePermissions()) {
            return false;
        }

        $createdBy = $project->getAttribute('created_by');

        if ($createdBy === null) {
            return false;
        }

        return (int) $createdBy === (int) $user->id
            && $this->hasPermission($user, 'resources.delete-own');
    }

    /**
     * Delete follows the same model as update.
     */
    public function delete(User $user, Project $project): bool
    {
        if (! $this->belongsToCurrentWorkspace($project)) {
            return false;
        }

        if ($this->isAdmin($user)) {
            return true;
        }

        if (! $this->supportsOwnResourcePermissions()) {
            return false;
        }

        $createdBy = $project->getAttribute('created_by');

        if ($createdBy === null) {
            return false;
        }

        return (int) $createdBy === (int) $user->id
            && $this->hasPermission($user, 'resources.delete-own');
    }

    /**
     * Own-resource permissions require a persisted ownership column.
     */
    private function supportsOwnResourcePermissions(): bool
    {
        if (self::$supportsOwnResourcePermissions === null) {
            self::$supportsOwnResourcePermissions = Schema::hasColumn('projects', 'created_by');
        }

        return self::$supportsOwnResourcePermissions;
    }
}
