<?php

namespace App\Traits;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\CurrentWorkspace;

trait HasWorkspaceRole
{
    /**
     * Get the user's role in the given workspace (or the current workspace context).
     * Returns null if the user is not a member.
     */
    public function getWorkspaceRole(?Workspace $workspace = null): ?WorkspaceRole
    {
        $workspace = $this->resolveWorkspace($workspace);

        if ($workspace === null) {
            return null;
        }

        $member = WorkspaceMember::where('workspace_id', $workspace->id)
            ->where('user_id', $this->id)
            ->first();

        if ($member === null) {
            return null;
        }

        return WorkspaceRole::tryFrom($member->role);
    }

    /**
     * Check if the user's role in the given workspace exactly matches the given role.
     */
    public function hasWorkspaceRole(WorkspaceRole $role, ?Workspace $workspace = null): bool
    {
        return $this->getWorkspaceRole($workspace) === $role;
    }

    /**
     * Check if the user is the owner of the given workspace.
     */
    public function isWorkspaceOwner(?Workspace $workspace = null): bool
    {
        return $this->hasWorkspaceRole(WorkspaceRole::OWNER, $workspace);
    }

    /**
     * Check if the user is an admin or owner of the given workspace.
     */
    public function isWorkspaceAdmin(?Workspace $workspace = null): bool
    {
        $role = $this->getWorkspaceRole($workspace);

        if ($role === null) {
            return false;
        }

        return $role->isAtLeast(WorkspaceRole::ADMIN);
    }

    /**
     * Check if the user has the given permission in the given workspace.
     */
    public function canInWorkspace(string $permission, ?Workspace $workspace = null): bool
    {
        $role = $this->getWorkspaceRole($workspace);

        if ($role === null) {
            return false;
        }

        return $role->can($permission);
    }

    /**
     * Resolve the workspace argument, falling back to the current request workspace context.
     */
    private function resolveWorkspace(?Workspace $workspace): ?Workspace
    {
        if ($workspace !== null) {
            return $workspace;
        }

        if (app()->bound(CurrentWorkspace::class)) {
            return app(CurrentWorkspace::class)->workspace;
        }

        return null;
    }
}
