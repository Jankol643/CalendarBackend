<?php

declare(strict_types = 1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter as FacadesRateLimiter;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider {

    /**
     * Register any application services.
     */
    public function register(): void {
    }

    public function boot(): void {
        FacadesRateLimiter::for('login', static fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
    }

}
