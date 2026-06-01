<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DeployProd extends Command
{
    protected $signature = 'deploy:prod';

    protected $description = 'Run all deployment steps for production (clear caches, migrate, rebuild caches)';

    public function handle(): int
    {
        $this->info('🚀 Deploying to production...');
        $this->newLine();

        $steps = [
            ['Clearing application cache', 'cache:clear', []],
            ['Clearing config cache', 'config:clear', []],
            ['Clearing route cache', 'route:clear', []],
            ['Clearing view cache', 'view:clear', []],
            ['Running migrations', 'migrate', ['--force' => true]],
            ['Caching config', 'config:cache', []],
            ['Caching routes', 'route:cache', []],
            ['Caching views', 'view:cache', []],
            ['Linking storage', 'storage:link', ['--force' => true]],
        ];

        foreach ($steps as [$name, $command, $args]) {
            $this->info("→ {$name}...");
            $exitCode = $this->call($command, $args);

            if ($exitCode !== self::SUCCESS) {
                $this->newLine();
                $this->error("✗ {$name} failed (exit code: {$exitCode}). Deployment aborted.");

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('✅ Deployment complete!');

        return self::SUCCESS;
    }
}
