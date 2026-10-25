<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UserPreferencesController extends Controller
{
    private const DEFAULT_CHANNELS = [
        'email' => true,
        'in_app' => true,
    ];

    private const DEFAULT_RULES = [
        'employee_changes' => true,
        'schedule_changes' => true,
        'learning_reserve' => false,
        'document_deadlines' => true,
    ];

    public function show(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $row = DB::table('user_preferences')->where('user_id', $userId)->first();

        return response()->json(['data' => $row === null
            ? [
                'notification_channels' => self::DEFAULT_CHANNELS,
                'notification_rules' => self::DEFAULT_RULES,
                'locale' => 'ru',
                'revision' => 0,
            ]
            : [
                'notification_channels' => $this->jsonObject($row->notification_channels, self::DEFAULT_CHANNELS),
                'notification_rules' => $this->jsonObject($row->notification_rules, self::DEFAULT_RULES),
                'locale' => $row->locale ?? 'ru',
                'revision' => (int) $row->revision,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'notification_channels' => ['required', 'array:email,in_app'],
            'notification_channels.email' => ['required', 'boolean'],
            'notification_channels.in_app' => ['required', 'boolean'],
            'notification_rules' => ['required', 'array:employee_changes,schedule_changes,learning_reserve,document_deadlines'],
            'notification_rules.employee_changes' => ['required', 'boolean'],
            'notification_rules.schedule_changes' => ['required', 'boolean'],
            'notification_rules.learning_reserve' => ['required', 'boolean'],
            'notification_rules.document_deadlines' => ['required', 'boolean'],
            'locale' => ['sometimes', 'in:ru,kk,en,ka'],
            'revision' => ['required', 'integer', 'min:0', 'max:2147483646'],
        ]);

        $userId = (int) $request->user()->getAuthIdentifier();
        $expected = (int) $validated['revision'];

        $nextRevision = DB::transaction(function () use ($userId, $expected, $validated): int {
            $current = DB::table('user_preferences')->where('user_id', $userId)->lockForUpdate()->first();

            if ($current === null) {
                if ($expected !== 0) {
                    throw ValidationException::withMessages([
                        'revision' => 'Preferences changed since they were loaded. Reload before saving.',
                    ]);
                }

                DB::table('user_preferences')->insert([
                    'user_id' => $userId,
                    'locale' => $validated['locale'] ?? 'ru',
                    'notification_channels' => json_encode($validated['notification_channels'], JSON_THROW_ON_ERROR),
                    'notification_rules' => json_encode($validated['notification_rules'], JSON_THROW_ON_ERROR),
                    'revision' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return 1;
            }

            if ((int) $current->revision !== $expected) {
                abort(409, 'Preferences changed since they were loaded. Reload before saving.');
            }

            $count = DB::table('user_preferences')
                ->where('user_id', $userId)
                ->where('revision', $expected)
                ->update([
                    'locale' => $validated['locale'] ?? $current->locale ?? 'ru',
                    'notification_channels' => json_encode($validated['notification_channels'], JSON_THROW_ON_ERROR),
                    'notification_rules' => json_encode($validated['notification_rules'], JSON_THROW_ON_ERROR),
                    'revision' => $expected + 1,
                    'updated_at' => now(),
                ]);
            abort_unless($count === 1, 409, 'Preferences changed since they were loaded.');

            return $expected + 1;
        }, 3);

        return response()->json(['data' => [
            'notification_channels' => $validated['notification_channels'],
            'notification_rules' => $validated['notification_rules'],
            'revision' => $nextRevision,
        ]]);
    }

    private function jsonObject(mixed $value, array $fallback): array
    {
        if (is_array($value)) {
            return array_replace($fallback, $value);
        }

        if (! is_string($value)) {
            return $fallback;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_replace($fallback, $decoded) : $fallback;
    }
}
