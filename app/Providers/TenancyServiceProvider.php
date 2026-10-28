<?php

namespace App\Providers;

use App\Models\Area;
use App\Models\Plant;
use App\Models\ProductionLine;
use App\Policies\AreaPolicy;
use App\Policies\PlantPolicy;
use App\Policies\ProductionLinePolicy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        Gate::policy(Plant::class, PlantPolicy::class);
        Gate::policy(Area::class, AreaPolicy::class);
        Gate::policy(ProductionLine::class, ProductionLinePolicy::class);
    }
}
