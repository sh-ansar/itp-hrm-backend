<?php

use App\Http\Middleware\ApiErrorCode;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureBrowserSessionEnabled;
use App\Http\Middleware\RequestCorrelationId;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First-party SPA requests may use the web session via auth:sanctum.
        // Only explicit first-party hosts should be placed in SANCTUM_STATEFUL_DOMAINS.
        $middleware->statefulApi();
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*', 'auth/*') ? null : '/login');
        $middleware->api(prepend: [RequestCorrelationId::class, ApiErrorCode::class]);
        $middleware->alias([
            'hrm.browser-login' => EnsureBrowserSessionEnabled::class,
            'hrm.active-account' => EnsureActiveAccount::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, \Throwable $error) =>
            $request->is('api/*', 'auth/*') || $request->expectsJson());
    })->create();
