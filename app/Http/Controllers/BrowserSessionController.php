<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class BrowserSessionController extends Controller
{
    private const MAX_PRINCIPAL_ATTEMPTS = 5;
    private const MAX_IP_ATTEMPTS = 20;
    private const DECAY_SECONDS = 60;

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $ip = $request->ip() ?? 'unknown';

        // Do not store plaintext email/IP in cache keys.
        $principalKey = 'hrm:login:principal:'.hash('sha256', $email.'|'.$ip);
        $ipKey = 'hrm:login:ip:'.hash('sha256', $ip);

        if (RateLimiter::tooManyAttempts($principalKey, self::MAX_PRINCIPAL_ATTEMPTS)
            || RateLimiter::tooManyAttempts($ipKey, self::MAX_IP_ATTEMPTS)) {
            $seconds = max(
                RateLimiter::availableIn($principalKey),
                RateLimiter::availableIn($ipKey),
                1,
            );

            return response()->json([
                'message' => 'Слишком много попыток входа. Попробуйте позже.',
            ], 429)->header('Retry-After', (string) $seconds);
        }

        // Both a missing account and an incorrect password return the
        // same response. Public registration is intentionally unavailable.
        if (!Auth::guard('web')->attempt([
            'email' => $email,
            'password' => $validated['password'],
        ], false)) {
            RateLimiter::hit($principalKey, self::DECAY_SECONDS);
            RateLimiter::hit($ipKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => 'Неверный адрес электронной почты или пароль.',
            ]);
        }

        RateLimiter::clear($principalKey);
        $request->session()->regenerate();

        return response()->json([
            'data' => $this->safeUser($request),
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->safeUser($request)]);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    private function safeUser(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $user->getAuthIdentifier(),
            'name' => $user->name,
        ];
    }
}
