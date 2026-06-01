<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class UniqueInWorkspace implements ValidationRule
{
    public function __construct(
        private readonly string $table,
        private readonly string $column,
        private readonly ?int $ignoreId = null,
    ) {}

    /**
     * Run the validation rule.
     *
     * Checks for uniqueness within the current workspace, excluding soft-deleted records,
     * so that soft-deleted resource names can be reused.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $workspaceId = current_workspace_id();

        if ($workspaceId === null) {
            // No workspace context; the controller will abort(404) before this
            // validation result is acted upon. Skip the check.
            return;
        }

        $exists = DB::table($this->table)
            ->where('workspace_id', $workspaceId)
            ->where($this->column, $value)
            ->whereNull('deleted_at')
            ->when($this->ignoreId !== null, fn ($q) => $q->where('id', '!=', $this->ignoreId))
            ->exists();

        if ($exists) {
            $fail('The :attribute has already been taken in this workspace.');
        }
    }
}
