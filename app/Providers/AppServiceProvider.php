<?php

namespace App\Providers;

use App\Http\Middleware\IsolatedXsrfCookie;
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
        // Sanctum's stateful API pipeline uses its own CSRF middleware setting,
        // separate from Laravel's web group. Both must issue the same cookie.
        config()->set('sanctum.middleware.validate_csrf_token', IsolatedXsrfCookie::class);
    }
}
