<?php

namespace App\Services;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

readonly class CurrentWorkspace
{
    public function __construct(
        public Workspace $workspace
    ) {}

    /**
     * Get the current workspace ID.
     */
    public function id(): int
    {
        return $this->workspace->id;
    }

    /**
     * Get the current workspace slug.
     */
    public function slug(): string
    {
        return $this->workspace->slug;
    }

    /**
     * Check if the given workspace is the current workspace.
     */
    public function is(Workspace $workspace): bool
    {
        return $this->workspace->id === $workspace->id;
    }

    /**
     * Get the authenticated user's role in the current workspace.
     * Returns null if user is not authenticated or not a member.
     */
    public function userRole(): ?string
    {
        if (! auth()->check()) {
            return null;
        }

        $member = WorkspaceMember::where('workspace_id', $this->workspace->id)
            ->where('user_id', auth()->id())
            ->first();

        return $member?->role;
    }

    /**
     * Check if the authenticated user has the given permission in the current workspace.
     */
    public function userCan(string $permission): bool
    {
        $roleString = $this->userRole();

        if ($roleString === null) {
            return false;
        }

        $role = WorkspaceRole::tryFrom($roleString);

        if ($role === null) {
            return false;
        }

        return $role->can($permission);
    }

    /**
     * Get the workspace name.
     */
    public function name(): string
    {
        return $this->workspace->name;
    }

    /**
     * Get the workspace domain.
     */
    public function domain(): ?string
    {
        return $this->workspace->domain;
    }

    /**
     * Get the workspace status.
     */
    public function status(): string
    {
        return $this->workspace->status;
    }

    /**
     * Check if the workspace is active.
     */
    public function isActive(): bool
    {
        return $this->workspace->status === 'active';
    }

    /**
     * Check if the workspace is suspended.
     */
    public function isSuspended(): bool
    {
        return $this->workspace->status === 'suspended';
    }

    /**
     * Check if the workspace is archived.
     */
    public function isArchived(): bool
    {
        return $this->workspace->status === 'archived';
    }
}
