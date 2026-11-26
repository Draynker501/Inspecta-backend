<?php

namespace App\Providers;

use App\Support\Auth\ProfileRateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for(
            'profile-update',
            function (Request $request): Limit {
                return Limit::perMinute(10)->by(
                    'profile-update:'
                    .ProfileRateLimitKey::resolve($request)
                );
            }
        );

        RateLimiter::for(
            'profile-password',
            function (Request $request): Limit {
                return Limit::perMinute(5)->by(
                    'profile-password:'
                    .ProfileRateLimitKey::resolve($request)
                );
            }
        );
    }
}
