<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;

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
            'profile-password',
            function (Request $request): Limit {
                $bearerToken = $request->bearerToken();

                if (is_string($bearerToken) && $bearerToken !== '') {
                    $accessToken = PersonalAccessToken::findToken(
                        $bearerToken
                    );

                    if ($accessToken !== null) {
                        return Limit::perMinute(5)->by(
                            'profile-password:user:'
                            .$accessToken->tokenable_type
                            .':'
                            .$accessToken->tokenable_id
                        );
                    }
                }

                return Limit::perMinute(5)->by(
                    'profile-password:ip:'.$request->ip()
                );
            }
        );
    }
}
