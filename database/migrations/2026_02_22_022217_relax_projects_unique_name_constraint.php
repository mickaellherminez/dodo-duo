<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Relax the DB-level (workspace_id, name) unique constraint on projects.
 *
 * The existing constraint covers ALL rows including soft-deleted ones, which
 * prevents reusing a soft-deleted project's name within the same workspace.
 * Uniqueness for active projects is now enforced by the UniqueInWorkspace
 * application-level rule (see app/Rules/UniqueInWorkspace.php) which excludes
 * soft-deleted records via whereNull('deleted_at').
 */
return new class extends Migration
{
    /**
     * Drop the DB-level unique constraint that blocks soft-deleted name reuse.
     */
    public function up(): void
    {
        if (! $this->indexExists('projects', 'projects_workspace_id_name_unique')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'name']);
        });
    }

    /**
     * Restore the original unique constraint.
     *
     * Note: this will fail if soft-deleted rows create duplicate (workspace_id, name)
     * combinations. In that case, resolve duplicates manually before rolling back.
     */
    public function down(): void
    {
        if ($this->indexExists('projects', 'projects_workspace_id_name_unique')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->unique(['workspace_id', 'name']);
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = collect($connection->select("PRAGMA index_list('$table')"));

            return $indexes->pluck('name')->contains($index);
        }

        if ($driver === 'mysql') {
            $database = $connection->getDatabaseName();
            $rows = $connection->select(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                [$database, $table, $index]
            );

            return count($rows) > 0;
        }

        if ($driver === 'pgsql') {
            $rows = $connection->select(
                'SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ? LIMIT 1',
                [$table, $index]
            );

            return count($rows) > 0;
        }

        return false;
    }
};
