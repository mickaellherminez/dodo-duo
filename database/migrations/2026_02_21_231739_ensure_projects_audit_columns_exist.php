<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ALLOWED_STATUSES = ['active', 'archived', 'completed'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('projects')) {
            return;
        }

        $needsCreatedBy = ! Schema::hasColumn('projects', 'created_by');
        $needsUpdatedBy = ! Schema::hasColumn('projects', 'updated_by');

        if ($needsCreatedBy || $needsUpdatedBy) {
            Schema::table('projects', function (Blueprint $table) use ($needsCreatedBy, $needsUpdatedBy): void {
                if ($needsCreatedBy) {
                    $table->foreignId('created_by')
                        ->nullable()
                        ->constrained('users')
                        ->nullOnDelete();
                }

                if ($needsUpdatedBy) {
                    $table->foreignId('updated_by')
                        ->nullable()
                        ->constrained('users')
                        ->nullOnDelete();
                }
            });
        }

        if ($this->hasInvalidStatuses()) {
            $this->normalizeLegacyStatuses();
        }

        $this->addStatusConstraintWhenSupported();

        if (Schema::hasColumn('projects', 'workspace_id') && ! $this->indexExists('projects', 'projects_workspace_id_index')) {
            Schema::table('projects', function (Blueprint $table): void {
                $table->index('workspace_id');
            });
        }

        if (Schema::hasColumn('projects', 'status') && ! $this->indexExists('projects', 'projects_status_index')) {
            Schema::table('projects', function (Blueprint $table): void {
                $table->index('status');
            });
        }

        if (Schema::hasColumn('projects', 'created_by') && ! $this->indexExists('projects', 'projects_created_by_index')) {
            Schema::table('projects', function (Blueprint $table): void {
                $table->index('created_by');
            });
        }

        if (Schema::hasColumn('projects', 'workspace_id')
            && Schema::hasColumn('projects', 'name')
            && ! $this->indexExists('projects', 'projects_workspace_id_name_unique')) {
            $this->normalizeDuplicateProjectNames();

            Schema::table('projects', function (Blueprint $table): void {
                $table->unique(['workspace_id', 'name']);
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * No-op intentionally: safe compatibility patch for existing databases.
     */
    public function down(): void {}

    private function hasInvalidStatuses(): bool
    {
        if (! Schema::hasColumn('projects', 'status')) {
            return false;
        }

        return DB::table('projects')
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereNotIn('status', self::ALLOWED_STATUSES);
            })
            ->exists();
    }

    private function normalizeLegacyStatuses(): void
    {
        DB::table('projects')
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereNotIn('status', self::ALLOWED_STATUSES);
            })
            ->update(['status' => 'active']);
    }

    private function addStatusConstraintWhenSupported(): void
    {
        $driver = DB::connection()->getDriverName();
        $constraint = 'projects_status_allowed_check';

        if ($driver !== 'mysql' && $driver !== 'pgsql') {
            return;
        }

        if ($this->constraintExists('projects', $constraint)) {
            return;
        }

        try {
            DB::statement(
                "ALTER TABLE projects ADD CONSTRAINT {$constraint} CHECK (status IN ('active', 'archived', 'completed'))"
            );
        } catch (QueryException $exception) {
            if ($this->shouldIgnoreStatusConstraintError($exception, $driver)) {
                return;
            }

            throw $exception;
        }
    }

    private function shouldIgnoreStatusConstraintError(QueryException $exception, string $driver): bool
    {
        $message = strtolower($exception->getMessage());

        if ($driver === 'mysql') {
            return (str_contains($message, "doesn't yet support") || str_contains($message, 'not supported'))
                && str_contains($message, 'check');
        }

        if ($driver === 'pgsql') {
            return (string) $exception->getCode() === '42710';
        }

        return false;
    }

    private function normalizeDuplicateProjectNames(): void
    {
        $duplicates = DB::table('projects')
            ->select('workspace_id', 'name', DB::raw('COUNT(*) as duplicate_count'))
            ->groupBy('workspace_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $projects = DB::table('projects')
                ->select('id', 'name')
                ->where('workspace_id', $duplicate->workspace_id)
                ->where('name', $duplicate->name)
                ->orderBy('id')
                ->get();

            $counter = 1;

            foreach ($projects->slice(1) as $project) {
                $newName = $this->buildDeduplicatedName(
                    (string) $duplicate->name,
                    (int) $duplicate->workspace_id,
                    (int) $project->id,
                    $counter
                );

                DB::table('projects')
                    ->where('id', $project->id)
                    ->update([
                        'name' => $newName,
                        'updated_at' => now(),
                    ]);

                $counter++;
            }
        }
    }

    private function buildDeduplicatedName(string $baseName, int $workspaceId, int $projectId, int $counter): string
    {
        while (true) {
            $suffix = " [dedup-{$projectId}";
            if ($counter > 1) {
                $suffix .= "-{$counter}";
            }
            $suffix .= ']';

            $maxBaseLength = max(1, 255 - strlen($suffix));
            $trimmedBase = mb_substr($baseName, 0, $maxBaseLength);
            $candidate = $trimmedBase.$suffix;

            $exists = DB::table('projects')
                ->where('workspace_id', $workspaceId)
                ->where('name', $candidate)
                ->where('id', '!=', $projectId)
                ->exists();

            if (! $exists) {
                return $candidate;
            }

            $counter++;
        }
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

    private function constraintExists(string $table, string $constraint): bool
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'mysql') {
            $database = $connection->getDatabaseName();
            $rows = $connection->select(
                'SELECT 1 FROM information_schema.table_constraints WHERE table_schema = ? AND table_name = ? AND constraint_name = ? LIMIT 1',
                [$database, $table, $constraint]
            );

            return count($rows) > 0;
        }

        if ($driver === 'pgsql') {
            $rows = $connection->select(
                'SELECT 1 FROM pg_constraint c INNER JOIN pg_class t ON c.conrelid = t.oid WHERE t.relname = ? AND c.conname = ? LIMIT 1',
                [$table, $constraint]
            );

            return count($rows) > 0;
        }

        return false;
    }
};
