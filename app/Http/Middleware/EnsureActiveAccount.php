<?php

namespace App\Http\Middleware;

use App\Domain\Identity\AccountState;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $authenticated = $request->user();
        $freshState = $authenticated === null
            ? null
            : User::query()->whereKey($authenticated->getAuthIdentifier())->value('account_status');

        if ($freshState === AccountState::Active) {
            return $next($request);
        }

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return new JsonResponse(['message' => 'Unauthenticated.'], 401);
    }
}
