<?php

use App\Http\Controllers\BrowserSessionController;
use App\Http\Controllers\ChangeOwnPasswordController;
use App\Http\Controllers\AccountRecoveryController;
use Illuminate\Support\Facades\Route;

Route::get('/', static fn () => view('welcome'));

// Public self-service recovery works independently of browser login readiness.
// Never issue links to reserve, suspended, disabled or unknown accounts.
Route::post('/auth/password/request', [AccountRecoveryController::class, 'requestLink'])
    ->middleware('throttle:20,1');
Route::post('/auth/password/reset', [AccountRecoveryController::class, 'reset'])
    ->middleware('throttle:20,1');

Route::prefix('auth')->middleware('hrm.browser-login')->group(function (): void {
    Route::post('/login', [BrowserSessionController::class, 'login']);

    Route::middleware(['auth:web', 'hrm.active-account'])->group(function (): void {
        Route::get('/me', [BrowserSessionController::class, 'current']);
        Route::post('/logout', [BrowserSessionController::class, 'logout']);
        Route::post('/password', ChangeOwnPasswordController::class)->middleware('throttle:5,1');
    });
});
