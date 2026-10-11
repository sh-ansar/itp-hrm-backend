<?php

use App\Http\Controllers\BrowserSessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', static fn () => view('welcome'));

// The routes use Laravel's web middleware: sessions and CSRF validation.
// API-only clients must not receive bearer tokens from this login endpoint.
Route::prefix('auth')->middleware('hrm.browser-login')->group(function (): void {
    Route::post('/login', [BrowserSessionController::class, 'login']);

    Route::middleware('auth:web')->group(function (): void {
        Route::get('/me', [BrowserSessionController::class, 'current']);
        Route::post('/logout', [BrowserSessionController::class, 'logout']);
    });
});
