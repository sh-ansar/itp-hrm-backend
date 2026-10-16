<?php

namespace App\Http\Controllers;

use App\Domain\Identity\AccountState;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

final class AccountRecoveryController extends Controller
{
    private function ensureEnabled(): void
    {
        abort_unless((bool) config('hrm.password_reset_enabled', false), 404);
    }

    public function requestLink(Request $request): Response
    {
        $this->ensureEnabled();
        $input = $request->validate(['email' => ['required', 'string', 'email', 'max:254']]);
        $email = mb_strtolower(trim($input['email']));
        $key = 'hrm:recovery:'.hash('sha256', $email.'|'.($request->ip() ?? 'unknown'));

        // Identical external responses for unknown, disabled and known users.
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->noContent();
        }
        RateLimiter::hit($key, 300);

        $active = User::query()->where('email', $email)
            ->where('account_status', AccountState::Active->value)->exists();

        if ($active) {
            try {
                $status = Password::broker('users')->sendResetLink(['email' => $email]);
                if ($status !== Password::RESET_LINK_SENT && $status !== Password::RESET_THROTTLED) {
                    Log::warning('HRM account recovery mail was not accepted by password broker');
                }
            } catch (\Throwable $exception) {
                // No address, credential, token or transport secrets in logs.
                Log::warning('HRM account recovery mail dispatch failed');
            }
        }

        return response()->noContent();
    }

    public function reset(Request $request): Response|JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'token' => ['required', 'string', 'max:256'],
            'password_confirmation' => ['required', 'string', 'max:1024'],
            'password' => ['required', 'string', 'max:1024', 'confirmed',
                PasswordRule::min(8)->numbers()->symbols()],
        ]);
        $email = mb_strtolower(trim($data['email']));
        $active = User::query()->where('email', $email)
            ->where('account_status', AccountState::Active->value)->exists();
        if (!$active) {
            return response()->json(['message' => 'The reset token is invalid or expired.'], 422);
        }

        $status = Password::broker('users')->reset([
            'email' => $email,
            'token' => $data['token'],
            'password' => $data['password'],
            'password_confirmation' => $data['password_confirmation'],
        ], static function (User $user, string $newPassword): void {
            DB::transaction(static function () use ($user, $newPassword): void {
                $user->forceFill([
                    'password' => Hash::make($newPassword),
                    'remember_token' => Str::random(60),
                ])->save();

                // An email recovery immediately invalidates all HRM web sessions.
                if (config('session.driver') === 'database') {
                    DB::table(config('session.table', 'sessions'))
                        ->where('user_id', $user->getAuthIdentifier())->delete();
                }
            });
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'The reset token is invalid or expired.'], 422);
        }

        return response()->noContent();
    }
}
