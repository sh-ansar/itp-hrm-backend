<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', static fn () => response()->json([
        'status' => 'ok',
        'service' => 'itp-hrm-api',
    ]));
    Route::middleware('auth:sanctum')->get('/me', static function (\Illuminate\Http\Request $request) {
        $user = $request->user();
        return response()->json(['data' => [
            'id' => $user->getAuthIdentifier(),
            'name' => $user->name,
        ]]);
    });
});
