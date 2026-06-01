<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetApiTestData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api:reset-test-data {--force : Force reset without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset API test data (workspaces and test user tokens)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (app()->environment('production')) {
            $this->error('❌ This command cannot be run in production!');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            if (! $this->confirm('⚠️  This will delete all workspaces and test user tokens. Continue?')) {
                $this->info('Operation cancelled.');

                return self::SUCCESS;
            }
        }

        $this->info('🔄 Resetting API test data...');
        $this->newLine();

        // Delete all workspace members
        $memberCount = DB::table('workspace_members')->count();
        DB::table('workspace_members')->delete();
        $this->line("✓ Deleted {$memberCount} workspace member(s)");

        // Force delete all workspaces (including soft-deleted)
        $workspaceCount = Workspace::withTrashed()->count();
        Workspace::withTrashed()->forceDelete();
        $this->line("✓ Deleted {$workspaceCount} workspace(s)");

        // Revoke all tokens for test user
        $testUser = User::where('email', 'test@example.com')->first();
        if ($testUser) {
            $tokenCount = $testUser->tokens()->count();
            $testUser->tokens()->delete();
            $this->line("✓ Revoked {$tokenCount} API token(s) for test user");
        }

        $this->newLine();
        $this->info('✨ Test data reset complete!');
        $this->newLine();

        if ($testUser) {
            $this->info('💡 Generate a new API token:');
            $this->line('   php artisan tinker');
            $this->line("   >>> User::find({$testUser->id})->createToken('test')->plainTextToken");
        }

        return self::SUCCESS;
    }
}
