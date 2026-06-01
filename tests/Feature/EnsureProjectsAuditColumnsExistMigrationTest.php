<?php

use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function runEnsureProjectsAuditColumnsMigration(): void
{
    /** @var \Illuminate\Database\Migrations\Migration $migration */
    $migration = require database_path('migrations/2026_02_21_231739_ensure_projects_audit_columns_exist.php');
    $migration->up();
}

describe('EnsureProjectsAuditColumnsExist migration', function () {
    test('repairs legacy projects schema and data drift', function () {
        Schema::dropIfExists('projects');

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 255)->nullable(); // Legacy drifted type (no enum/check)
            $table->timestamps();
            $table->softDeletes();
        });

        $workspace = Workspace::factory()->create();

        DB::table('projects')->insert([
            [
                'workspace_id' => $workspace->id,
                'name' => 'Legacy Name',
                'status' => 'legacy',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'workspace_id' => $workspace->id,
                'name' => 'Legacy Name',
                'status' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'workspace_id' => $workspace->id,
                'name' => 'Legacy Name',
                'status' => 'archived',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        runEnsureProjectsAuditColumnsMigration();

        expect(Schema::hasColumn('projects', 'created_by'))->toBeTrue()
            ->and(Schema::hasColumn('projects', 'updated_by'))->toBeTrue();

        $indexNames = collect(Schema::getIndexes('projects'))->pluck('name');

        expect($indexNames)->toContain('projects_workspace_id_index')
            ->toContain('projects_status_index')
            ->toContain('projects_created_by_index')
            ->toContain('projects_workspace_id_name_unique');

        $rows = DB::table('projects')->orderBy('id')->get();
        $names = $rows->pluck('name');
        $statuses = $rows->pluck('status');

        expect($statuses[0])->toBe('active')
            ->and($statuses[1])->toBe('active')
            ->and($statuses[2])->toBe('archived')
            ->and($names->unique()->count())->toBe(3);

        expect(fn () => DB::table('projects')->insert([
            'workspace_id' => $workspace->id,
            'name' => (string) $names[0],
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    test('is idempotent when executed multiple times', function () {
        runEnsureProjectsAuditColumnsMigration();
        runEnsureProjectsAuditColumnsMigration();

        expect(Schema::hasColumn('projects', 'created_by'))->toBeTrue()
            ->and(Schema::hasColumn('projects', 'updated_by'))->toBeTrue();
    });
});
