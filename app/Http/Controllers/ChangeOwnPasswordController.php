<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class ChangeOwnPasswordController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:1024'],
            'password' => ['required', 'string', 'max:1024', 'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        if (Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'The new password must differ from the current password.',
            ]);
        }

        DB::transaction(function () use ($user, $request, $validated): void {
            // Hashed Eloquent cast: plaintext is never saved in the database.
            $user->forceFill(['password' => $validated['password']])->save();

            // Changing a password invalidates other device sessions, but not
            // the currently authenticated browser. Only the isolated HRM DB.
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->getAuthIdentifier())
                    ->where('id', '!=', $request->session()->getId())
                    ->delete();
            }
        });

        $request->session()->regenerate();

        return response()->noContent();
    }
}
