<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BenchmarkPerformance extends Command
{
    protected $signature = 'benchmark:run {--samples=100}';

    protected $description = 'Run performance benchmarks to validate shared hosting compatibility';

    public function handle(): int
    {
        $samples = (int) $this->option('samples');

        if ($samples <= 0) {
            $this->error('Samples must be greater than 0.');

            return self::FAILURE;
        }

        $this->info('Running performance benchmarks...');
        $this->newLine();

        $results = [
            $this->benchmarkQuery($samples),
            $this->benchmarkModelCreation($samples),
            $this->benchmarkCache($samples),
            $this->benchmarkApiEndpoint($samples),
        ];

        $this->table(
            ['Benchmark', 'Avg (ms)', 'P95 (ms)', 'P99 (ms)', 'Status'],
            $results
        );

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function benchmarkQuery(int $samples): array
    {
        /** @var array<int, float> $times */
        $times = [];

        for ($i = 0; $i < $samples; $i++) {
            $start = microtime(true);
            Project::with(['workspace', 'creator'])->limit(50)->get();
            $times[] = (microtime(true) - $start) * 1000;
        }

        return $this->formatRow('Database Query', $this->calculateStats($times, 100));
    }

    /**
     * @return array<int, string>
     */
    protected function benchmarkModelCreation(int $samples): array
    {
        /** @var array<int, float> $times */
        $times = [];

        for ($i = 0; $i < $samples; $i++) {
            DB::beginTransaction();
            try {
                $start = microtime(true);
                $user = User::factory()->create();
                Workspace::factory()->create(['owner_id' => $user->id]);
                $times[] = (microtime(true) - $start) * 1000;
            } finally {
                DB::rollBack();
            }
        }

        return $this->formatRow('Model Creation', $this->calculateStats($times, 50));
    }

    /**
     * @return array<int, string>
     */
    protected function benchmarkCache(int $samples): array
    {
        /** @var array<int, float> $times */
        $times = [];
        $key = 'benchmark_test_'.uniqid();

        for ($i = 0; $i < $samples; $i++) {
            $start = microtime(true);
            Cache::put($key, 'value', 60);
            Cache::has($key);
            Cache::forget($key);
            $times[] = (microtime(true) - $start) * 1000;
        }

        return $this->formatRow('Cache Operations', $this->calculateStats($times, 10));
    }

    /**
     * @return array<int, string>
     */
    protected function benchmarkApiEndpoint(int $samples): array
    {
        /** @var array<int, float> $times */
        $times = [];

        DB::beginTransaction();
        try {
            $user = User::factory()->create();
            $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
            $workspace->addMember($user, 'owner');
            $token = $user->createToken('benchmark')->plainTextToken;

            $kernel = $this->laravel->make(\Illuminate\Contracts\Http\Kernel::class);

            for ($i = 0; $i < $samples; $i++) {
                $start = microtime(true);
                $request = \Illuminate\Http\Request::create('/api/v1/workspaces', 'GET', [], [], [], [
                    'HTTP_AUTHORIZATION' => "Bearer {$token}",
                    'HTTP_ACCEPT' => 'application/json',
                ]);
                $response = $kernel->handle($request);
                $times[] = (microtime(true) - $start) * 1000;
                $kernel->terminate($request, $response);
            }
        } finally {
            DB::rollBack();
        }

        return $this->formatRow('API Endpoint', $this->calculateStats($times, 300));
    }

    /**
     * @param  array<int, float>  $times
     * @return array{avg: float, p95: float, p99: float, status: string}
     */
    protected function calculateStats(array $times, float $target): array
    {
        sort($times);
        $count = count($times);

        $avg = array_sum($times) / $count;
        $p95 = $times[(int) ceil(0.95 * $count) - 1];
        $p99 = $times[(int) ceil(0.99 * $count) - 1];

        return [
            'avg' => round($avg, 2),
            'p95' => round($p95, 2),
            'p99' => round($p99, 2),
            'status' => $p95 <= $target ? '✅ Pass' : '❌ Fail',
        ];
    }

    /**
     * @param  array{avg: float, p95: float, p99: float, status: string}  $stats
     * @return array<int, string>
     */
    protected function formatRow(string $name, array $stats): array
    {
        return [
            $name,
            (string) $stats['avg'],
            (string) $stats['p95'],
            (string) $stats['p99'],
            $stats['status'],
        ];
    }
}
