<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Observers\ProjectObserver;
use App\Observers\WorkspaceMemberObserver;
use App\Policies\AuditLogPolicy;
use App\Policies\ProjectPolicy;
use App\Swagger\DocblockAwareL5SwaggerGenerator;
use App\Swagger\DocblockAwareL5SwaggerGeneratorFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use L5Swagger\ConfigFactory;
use L5Swagger\Generator as L5SwaggerGenerator;
use L5Swagger\GeneratorFactory as L5SwaggerGeneratorFactory;
use L5Swagger\SecurityDefinitions;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(L5SwaggerGeneratorFactory::class, DocblockAwareL5SwaggerGeneratorFactory::class);

        // L5-Swagger v10 defaults to attribute-only scanning. Story 9.2 requires
        // PHPDoc @OA annotations, so we swap in a generator that enables both.
        $this->app->bind(L5SwaggerGenerator::class, function ($app) {
            $documentation = config('l5-swagger.default');
            $config = $app->make(ConfigFactory::class)->documentationConfig($documentation);

            $paths = $config['paths'];
            $scanOptions = $config['scanOptions'] ?? [];
            $constants = $config['constants'] ?? [];
            $yamlCopyRequired = $config['generate_yaml_copy'] ?? false;

            $secSchemesConfig = $config['securityDefinitions']['securitySchemes'] ?? [];
            $secConfig = $config['securityDefinitions']['security'] ?? [];

            $security = new SecurityDefinitions($secSchemesConfig, $secConfig);

            return new DocblockAwareL5SwaggerGenerator(
                $paths,
                $constants,
                $yamlCopyRequired,
                $security,
                $scanOptions
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);

        Gate::define('viewAdminDashboard', fn (User $user): bool => (bool) $user->is_super_admin);
        Gate::define('manageAllWorkspaces', fn (User $user): bool => (bool) $user->is_super_admin);

        Project::observe(ProjectObserver::class);
        WorkspaceMember::observe(WorkspaceMemberObserver::class);

        Model::preventLazyLoading(! app()->isProduction());

        DB::listen(function ($query) {
            if ($query->time > 100) {
                Log::warning('Slow query detected', [
                    'sql' => $query->sql,
                    'time' => $query->time.'ms',
                ]);
            }
        });
    }
}
