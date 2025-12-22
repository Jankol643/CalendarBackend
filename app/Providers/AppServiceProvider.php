<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter as FacadesRateLimiter;
use Illuminate\Support\Facades\Request;

class AppServiceProvider extends ServiceProvider {
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register() {
        //
    }

    public function boot() {
        FacadesRateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip()); // Limit to 5 attempts per minute per IP
        });
    }
}
