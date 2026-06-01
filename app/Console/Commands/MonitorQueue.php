<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MonitorQueue extends Command
{
    protected $signature = 'queue:monitor';

    protected $description = 'Display queue and failed jobs counts';

    public function handle(): int
    {
        $jobs = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->count();

        $this->info('Queue Status');
        $this->table(
            ['Queue', 'Count'],
            [
                ['jobs', $jobs],
                ['failed_jobs', $failed],
            ]
        );

        if ($failed > 0) {
            $this->warn("⚠️  {$failed} failed job(s) detected. Run: php artisan queue:retry all");
        }

        return self::SUCCESS;
    }
}
