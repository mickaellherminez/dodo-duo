<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

class PruneAuditLogs extends Command
{
    protected $signature = 'audit:prune {--days=90 : Delete logs older than this many days}';

    protected $description = 'Delete audit log records older than the specified number of days';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('The --days option must be at least 1.');

            return self::FAILURE;
        }

        $deleted = AuditLog::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Deleted {$deleted} audit log(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
