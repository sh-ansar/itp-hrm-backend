<?php

use App\Http\Controllers\Api\V1\EmployeeIndexController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', static fn () => response()->json([
        'status' => 'ok',
        'service' => 'itp-hrm-api',
    ]));

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', static function (Request $request) {
            $user = $request->user();

            return response()->json(['data' => [
                'id' => $user->getAuthIdentifier(),
                'name' => $user->name,
            ]]);
        });

        Route::get('/companies/{company}/employees', EmployeeIndexController::class)
            ->whereUuid('company');
    });
});
