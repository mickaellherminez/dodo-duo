<?php

namespace App\Models\Concerns;

use App\Models\Workspace;
use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * BelongsToWorkspace Trait
 *
 * Provides multi-tenant workspace isolation for Eloquent models.
 *
 * Features:
 * - Automatic workspace_id filtering via WorkspaceScope
 * - Auto-fill workspace_id from current workspace context on creation
 * - Immutable workspace_id (cannot be changed after creation)
 * - Workspace relationship and cross-workspace query scope
 *
 * Usage:
 * ```php
 * class Project extends Model
 * {
 *     use BelongsToWorkspace;
 * }
 * ```
 *
 * @property int $workspace_id
 *
 * @method static Builder acrossWorkspaces()
 * @method static Builder forAllWorkspaces()
 */
trait BelongsToWorkspace
{
    /**
     * Boot the BelongsToWorkspace trait for a model.
     *
     * Registers global scope and model event observers.
     */
    protected static function bootBelongsToWorkspace(): void
    {
        // Apply WorkspaceScope global scope to automatically filter queries
        static::addGlobalScope(new WorkspaceScope);

        // Auto-fill workspace_id on model creation
        static::creating(function ($model) {
            // If workspace_id is already set explicitly, use it
            if (isset($model->workspace_id)) {
                return;
            }

            // Get workspace_id from current workspace context
            $workspaceId = current_workspace_id();

            if ($workspaceId === null) {
                throw new RuntimeException('Cannot create model without workspace context. Set workspace context via app()->instance(CurrentWorkspace::class, ...) or provide workspace_id explicitly.');
            }

            $model->workspace_id = $workspaceId;
        });

        // Prevent workspace_id from being modified after creation (immutable)
        static::updating(function ($model) {
            if ($model->isDirty('workspace_id')) {
                throw new RuntimeException('workspace_id cannot be modified after creation. This field is immutable to maintain data integrity.');
            }
        });
    }

    /**
     * Get the workspace that owns this model.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Query scope to retrieve records across all workspaces.
     *
     * Removes the WorkspaceScope global scope for this query only.
     * Useful for admin operations or cross-workspace reporting.
     *
     * Usage:
     * ```php
     * Project::acrossWorkspaces()->get(); // All projects, any workspace
     * ```
     */
    public function scopeAcrossWorkspaces(Builder $query): Builder
    {
        return $query->withoutGlobalScope(WorkspaceScope::class);
    }

    /**
     * Escape hatch alias for admin features needing cross-workspace queries.
     *
     * This is a readability alias for acrossWorkspaces() to match documentation
     * and epic terminology without breaking existing code/tests.
     */
    public function scopeForAllWorkspaces(Builder $query): Builder
    {
        return $this->scopeAcrossWorkspaces($query);
    }
}
