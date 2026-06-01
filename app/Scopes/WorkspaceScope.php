<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * WorkspaceScope - Global scope to automatically filter queries by workspace_id
 *
 * This scope is applied to all models using the BelongsToWorkspace trait.
 * It ensures that queries only return records belonging to the current workspace context.
 *
 * @see \App\Models\Concerns\BelongsToWorkspace
 */
class WorkspaceScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * Automatically adds WHERE workspace_id = ? to all queries when workspace context is set.
     * If current_workspace_id() is null, no filter is applied (allows seeding/admin operations).
     */
    public function apply(Builder $builder, Model $model): void
    {
        $workspaceId = current_workspace_id();

        if ($workspaceId !== null) {
            $builder->where($model->getTable().'.workspace_id', $workspaceId);
        }
    }
}
