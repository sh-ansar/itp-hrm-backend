<?php

use App\Http\Controllers\BrowserSessionController;
use App\Http\Controllers\ChangeOwnPasswordController;
use Illuminate\Support\Facades\Route;

Route::get('/', static fn () => view('welcome'));

Route::prefix('auth')->middleware('hrm.browser-login')->group(function (): void {
    Route::post('/login', [BrowserSessionController::class, 'login']);

    Route::middleware(['auth:web', 'hrm.active-account'])->group(function (): void {
        Route::get('/me', [BrowserSessionController::class, 'current']);
        Route::post('/logout', [BrowserSessionController::class, 'logout']);
        Route::post('/password', ChangeOwnPasswordController::class)->middleware('throttle:5,1');
    });
});
