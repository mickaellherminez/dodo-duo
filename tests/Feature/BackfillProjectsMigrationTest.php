<?php

use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Test subclass exposing protected migration helpers.
 * BackfillProjectsAuditColumnsAndIndexes is loaded by RefreshDatabase via require_once
 * before these tests run, so the named class is available here.
 */
function makeBackfillMigration(): object
{
    return new class extends BackfillProjectsAuditColumnsAndIndexes
    {
        public function runNormalizeLegacyStatuses(): void
        {
            $this->normalizeLegacyStatuses();
        }

        public function runNormalizeDuplicateProjectNames(): void
        {
            $this->normalizeDuplicateProjectNames();
        }

        public function runBuildDeduplicatedName(string $name, int $wsId, int $projId, int $counter): string
        {
            return $this->buildDeduplicatedName($name, $wsId, $projId, $counter);
        }

        public function runHasInvalidStatuses(): bool
        {
            return $this->hasInvalidStatuses();
        }
    };
}

describe('BackfillProjectsMigration — normalizeLegacyStatuses', function () {
    beforeEach(function () {
        // Widen status to a plain string so tests can insert non-enum / null values
        // without hitting the SQLite CHECK constraint added by the enum migration.
        Schema::table('projects', fn ($t) => $t->string('status', 255)->nullable()->change());
    });

    test('returns false for hasInvalidStatuses when all statuses are valid', function () {
        $workspace = Workspace::factory()->create();

        DB::table('projects')->insert([
            'workspace_id' => $workspace->id,
            'name' => 'Good Status',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(makeBackfillMigration()->runHasInvalidStatuses())->toBeFalse();
    });

    test('detects and normalizes rows with invalid legacy status', function () {
        $workspace = Workspace::factory()->create();

        DB::table('projects')->insert([
            ['workspace_id' => $workspace->id, 'name' => 'Bad Status', 'status' => 'legacy', 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $workspace->id, 'name' => 'Null Status', 'status' => null, 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $workspace->id, 'name' => 'Good Status', 'status' => 'archived', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration = makeBackfillMigration();
        expect($migration->runHasInvalidStatuses())->toBeTrue();

        $migration->runNormalizeLegacyStatuses();

        $rows = DB::table('projects')->orderBy('id')->get();

        expect($rows[0]->status)->toBe('active')   // was 'legacy'
            ->and($rows[1]->status)->toBe('active') // was null
            ->and($rows[2]->status)->toBe('archived'); // untouched
    });

    test('does not touch rows with valid statuses', function () {
        $workspace = Workspace::factory()->create();

        DB::table('projects')->insert([
            ['workspace_id' => $workspace->id, 'name' => 'Active', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $workspace->id, 'name' => 'Archived', 'status' => 'archived', 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $workspace->id, 'name' => 'Completed', 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()],
        ]);

        makeBackfillMigration()->runNormalizeLegacyStatuses();

        $statuses = DB::table('projects')->orderBy('id')->pluck('status')->all();
        expect($statuses)->toBe(['active', 'archived', 'completed']);
    });
});

describe('BackfillProjectsMigration — normalizeDuplicateProjectNames', function () {
    beforeEach(function () {
        // Drop unique constraint to allow inserting duplicates for these tests.
        // Guard: Story 5.4 removed this index; only drop if it still exists.
        if (collect(Schema::getIndexes('projects'))->pluck('name')->contains('projects_workspace_id_name_unique')) {
            Schema::table('projects', fn ($t) => $t->dropUnique('projects_workspace_id_name_unique'));
        }
    });

    afterEach(function () {
        // Restore the unique constraint after each test.
        if (! collect(Schema::getIndexes('projects'))->pluck('name')->contains('projects_workspace_id_name_unique')) {
            Schema::table('projects', fn ($t) => $t->unique(['workspace_id', 'name']));
        }
    });

    test('preserves the first occurrence and renames subsequent duplicates', function () {
        $workspace = Workspace::factory()->create();

        $firstId = DB::table('projects')->insertGetId(['workspace_id' => $workspace->id, 'name' => 'Alpha', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $secondId = DB::table('projects')->insertGetId(['workspace_id' => $workspace->id, 'name' => 'Alpha', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $thirdId = DB::table('projects')->insertGetId(['workspace_id' => $workspace->id, 'name' => 'Alpha', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        makeBackfillMigration()->runNormalizeDuplicateProjectNames();

        $names = DB::table('projects')->orderBy('id')->pluck('name', 'id');

        expect($names[$firstId])->toBe('Alpha')                          // first preserved
            ->and($names[$secondId])->toStartWith('Alpha [dedup-')       // second renamed
            ->and($names[$thirdId])->toStartWith('Alpha [dedup-')        // third renamed
            ->and($names[$secondId])->not->toBe($names[$thirdId]);       // names are distinct
    });

    test('leaves names unchanged when no duplicates exist', function () {
        $workspace = Workspace::factory()->create();

        DB::table('projects')->insert([
            ['workspace_id' => $workspace->id, 'name' => 'Alpha', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $workspace->id, 'name' => 'Beta', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        makeBackfillMigration()->runNormalizeDuplicateProjectNames();

        $names = DB::table('projects')->orderBy('id')->pluck('name')->all();
        expect($names)->toBe(['Alpha', 'Beta']);
    });

    test('allows same name in different workspaces — only intra-workspace duplicates renamed', function () {
        $ws1 = Workspace::factory()->create();
        $ws2 = Workspace::factory()->create();

        DB::table('projects')->insert([
            ['workspace_id' => $ws1->id, 'name' => 'Alpha', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $ws2->id, 'name' => 'Alpha', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        makeBackfillMigration()->runNormalizeDuplicateProjectNames();

        $names = DB::table('projects')->orderBy('id')->pluck('name')->all();
        expect($names)->toBe(['Alpha', 'Alpha']); // no rename — different workspaces
    });
});

describe('BackfillProjectsMigration — buildDeduplicatedName', function () {
    test('generates suffix [dedup-{id}] for first collision (counter=1)', function () {
        $workspace = Workspace::factory()->create();

        $result = makeBackfillMigration()->runBuildDeduplicatedName('Alpha', $workspace->id, 99, 1);

        expect($result)->toBe('Alpha [dedup-99]');
    });

    test('generates suffix [dedup-{id}-{counter}] when counter > 1', function () {
        $workspace = Workspace::factory()->create();

        $result = makeBackfillMigration()->runBuildDeduplicatedName('Alpha', $workspace->id, 99, 3);

        expect($result)->toBe('Alpha [dedup-99-3]');
    });

    test('truncates base name to keep total length within 255 characters', function () {
        $workspace = Workspace::factory()->create();
        $longName = str_repeat('x', 255);
        $suffix = ' [dedup-99]'; // 11 chars

        $result = makeBackfillMigration()->runBuildDeduplicatedName($longName, $workspace->id, 99, 1);

        expect(strlen($result))->toBeLessThanOrEqual(255)
            ->and($result)->toEndWith($suffix);
    });

    test('increments counter when candidate name already exists in workspace', function () {
        $workspace = Workspace::factory()->create();

        // Pre-insert the first candidate to force counter increment
        DB::table('projects')->insert([
            'workspace_id' => $workspace->id,
            'name' => 'Alpha [dedup-99]',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = makeBackfillMigration()->runBuildDeduplicatedName('Alpha', $workspace->id, 99, 1);

        // First candidate 'Alpha [dedup-99]' exists → should fall back to counter 2
        expect($result)->toBe('Alpha [dedup-99-2]');
    });
});
