<?php

namespace App\Observers;

use App\Models\Project;
use Illuminate\Support\Facades\Auth;

class ProjectObserver
{
    /**
     * Handle the Project "creating" event.
     */
    public function creating(Project $project): void
    {
        if (Auth::check()) {
            $project->created_by = Auth::id();
        }
    }

    /**
     * Handle the Project "updating" event.
     *
     * Skips audit fill when the only change is a soft-delete/restore (deleted_at),
     * so updated_by tracks editors rather than who deleted/restored the record.
     */
    public function updating(Project $project): void
    {
        if (Auth::check() && ! $project->isDirty('deleted_at')) {
            $project->updated_by = Auth::id();
        }
    }
}
