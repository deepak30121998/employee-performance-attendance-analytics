<?php

namespace Modules\Performance\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Performance\Contracts\PerformanceRepositoryInterface;
use Modules\Performance\Models\PerformanceScore;
use Modules\Performance\Observers\PerformanceScoreObserver;
use Modules\Performance\Policies\PerformanceScorePolicy;
use Modules\Performance\Repositories\PerformanceRepository;
use Nwidart\Modules\Support\ModuleServiceProvider;

class PerformanceServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Performance';

    protected string $nameLower = 'performance';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(PerformanceRepositoryInterface::class, PerformanceRepository::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(PerformanceScore::class, PerformanceScorePolicy::class);

        PerformanceScore::observe(PerformanceScoreObserver::class);
    }
}
