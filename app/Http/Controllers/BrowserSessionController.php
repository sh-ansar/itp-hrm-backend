<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
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
            'account_status' => \App\Domain\Identity\AccountState::Active->value,
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

    /** Change the authenticated user's password without email or public reset links. */
    public function changePassword(Request $request): Response
    {
        $user = $request->user('web');
        $limitKey = 'hrm:password-change:'.hash('sha256', (string) $user->getAuthIdentifier().'|'.($request->ip() ?? 'unknown'));

        if (RateLimiter::tooManyAttempts($limitKey, 5)) {
            return response()->noContent(429)->header('Retry-After', (string) max(1, RateLimiter::availableIn($limitKey)));
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:1024'],
            'new_password' => ['required', 'string', 'max:1024', 'confirmed', Password::min(14)->mixedCase()->numbers()->symbols()],
        ]);

        if (!Hash::check($validated['current_password'], $user->getAuthPassword())) {
            RateLimiter::hit($limitKey, 300);
            throw ValidationException::withMessages(['current_password' => 'Текущий пароль указан неверно.']);
        }
        if (Hash::check($validated['new_password'], $user->getAuthPassword())) {
            throw ValidationException::withMessages(['new_password' => 'Новый пароль должен отличаться от текущего.']);
        }

        DB::transaction(function () use ($request, $user, $validated): void {
            $user->forceFill([
                'password' => Hash::make($validated['new_password']),
                'remember_token' => \Illuminate\Support\Str::random(60),
            ])->save();

            // Revoke every other HRM session. The regenerated current session
            // is persisted after this request; it is the only remaining login.
            $request->session()->regenerate();
            DB::table('sessions')->where('user_id', $user->getAuthIdentifier())
                ->where('id', '!=', $request->session()->getId())->delete();
        });
        RateLimiter::clear($limitKey);

        return response()->noContent();
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
