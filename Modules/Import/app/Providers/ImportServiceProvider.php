<?php

namespace Modules\Import\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Policies\ImportBatchPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ImportServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Import';

    protected string $nameLower = 'import';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Gate::policy(ImportBatch::class, ImportBatchPolicy::class);
    }
}
