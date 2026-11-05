<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class HrmPlatformAccess
{
    public const PERMISSIONS = [
        'platform.manage', 'companies.read', 'users.write', 'plans.read', 'plans.write',
        'subscriptions.write', 'billing.read', 'billing.write',
        'legal.read', 'legal.write', 'legal.approve', 'legal.publish',
    ];

    public static function allows(?int $userId, string $permission): bool
    {
        if (!$userId) return false;
        if ($permission === 'platform.access') {
            return DB::table('hrm_platform_admin_grants')->where('user_id', $userId)->exists();
        }
        return DB::table('hrm_platform_admin_grants')
            ->where('user_id', $userId)
            ->whereIn('permission', ['platform.manage', $permission])
            ->exists();
    }

    public static function require(Request $request, string $permission): int
    {
        $user = $request->user();
        abort_unless($user && self::allows((int) $user->id, $permission), 403);
        return (int) $user->id;
    }

    public static function audit(?int $actor, ?string $company, string $action, string $subject, array $details = []): void
    {
        DB::table('hrm_commercial_audits')->insert([
            'actor_id' => $actor,
            'company_id' => $company,
            'action' => $action,
            'subject' => $subject,
            'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }

    public static function plan(object $p): array
    {
        return [
            'id' => $p->id, 'code' => $p->code, 'version' => $p->version,
            'display_order' => (int) ($p->display_order ?? 0),
            'sha256' => $p->sha256 ?? null,
            'status' => $p->status, 'is_public' => (bool) $p->is_public,
            'terms' => [
                'names' => json_decode($p->names, true) ?: [],
                'descriptions' => json_decode($p->descriptions ?? '{}', true) ?: [],
                'price_minor' => (int) $p->monthly_price_minor,
                'currency' => $p->currency,
                'period_months' => (int) ($p->period_months ?? 1),
                'limits' => json_decode($p->limits, true) ?: [],
                'modules' => json_decode($p->modules, true) ?: [],
            ],
        ];
    }
}
