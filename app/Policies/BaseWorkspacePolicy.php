<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrentWorkspace;
use Illuminate\Database\Eloquent\Model;

abstract class BaseWorkspacePolicy
{
    /**
     * Resolve current workspace from request context.
     */
    protected function workspace(): ?Workspace
    {
        return $this->resolveWorkspace();
    }

    /**
     * Resolve workspace from explicit argument first, then request context.
     */
    protected function resolveWorkspace(?Workspace $workspace = null): ?Workspace
    {
        if ($workspace !== null) {
            return $workspace;
        }

        if (! app()->bound(CurrentWorkspace::class)) {
            return null;
        }

        return app(CurrentWorkspace::class)->workspace;
    }

    /**
     * Check if user is workspace owner.
     */
    protected function isOwner(User $user, ?Workspace $workspace = null): bool
    {
        $workspace = $this->resolveWorkspace($workspace);

        if ($workspace === null) {
            return false;
        }

        // Owner fallback keeps compatibility with records missing owner membership rows.
        if ((int) $workspace->owner_id === (int) $user->id) {
            return true;
        }

        return $user->isWorkspaceOwner($workspace);
    }

    /**
     * Check if user is admin or owner.
     */
    protected function isAdmin(User $user, ?Workspace $workspace = null): bool
    {
        if ($this->isOwner($user, $workspace)) {
            return true;
        }

        $workspace = $this->resolveWorkspace($workspace);

        if ($workspace === null) {
            return false;
        }

        return $user->isWorkspaceAdmin($workspace);
    }

    /**
     * Check if user is any workspace member.
     */
    protected function isMember(User $user, ?Workspace $workspace = null): bool
    {
        if ($this->isOwner($user, $workspace)) {
            return true;
        }

        $workspace = $this->resolveWorkspace($workspace);

        if ($workspace === null) {
            return false;
        }

        return $user->getWorkspaceRole($workspace) !== null;
    }

    /**
     * Check if user holds a workspace-scoped permission.
     */
    protected function hasPermission(User $user, string $permission, ?Workspace $workspace = null): bool
    {
        if ($this->isOwner($user, $workspace)) {
            return true;
        }

        $workspace = $this->resolveWorkspace($workspace);

        if ($workspace === null) {
            return false;
        }

        return $user->canInWorkspace($permission, $workspace);
    }

    /**
     * Ensure model belongs to current workspace context.
     */
    protected function belongsToCurrentWorkspace(Model $model, ?Workspace $workspace = null): bool
    {
        $workspace = $this->resolveWorkspace($workspace);

        if ($workspace === null) {
            return false;
        }

        $workspaceId = $model->getAttribute('workspace_id');

        if ($workspaceId === null) {
            return false;
        }

        return (int) $workspaceId === (int) $workspace->id;
    }
}
