<?php

use App\Services\CurrentWorkspace;

if (! function_exists('current_workspace')) {
    /**
     * Get the current workspace instance.
     */
    function current_workspace(): ?CurrentWorkspace
    {
        return app()->bound(CurrentWorkspace::class)
            ? app(CurrentWorkspace::class)
            : null;
    }
}

if (! function_exists('current_workspace_id')) {
    /**
     * Get the current workspace ID.
     */
    function current_workspace_id(): ?int
    {
        return current_workspace()?->id();
    }
}

if (! function_exists('current_workspace_slug')) {
    /**
     * Get the current workspace slug.
     */
    function current_workspace_slug(): ?string
    {
        return current_workspace()?->slug();
    }
}

if (! function_exists('workspace_owns')) {
    /**
     * Check if the current workspace owns the given model.
     *
     * @param  mixed  $model
     */
    function workspace_owns($model): bool
    {
        if (! current_workspace_id()) {
            return false;
        }

        if (! $model || ! isset($model->workspace_id)) {
            return false;
        }

        return $model->workspace_id === current_workspace_id();
    }
}
