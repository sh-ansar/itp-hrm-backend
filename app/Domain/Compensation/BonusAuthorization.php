<?php

namespace App\Domain\Compensation;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Role-scoped access for sensitive monetary HR decisions. */
final class BonusAuthorization
{
    public function canManage(User $actor, string $company): bool
    {
        return $this->hasRole($actor, $company, ['company_admin', 'hr_specialist']);
    }

    public function canApprove(User $actor, string $company): bool
    {
        return $this->hasRole($actor, $company, ['company_admin']);
    }

    private function hasRole(User $actor, string $company, array $roles): bool
    {
        if (! config('hrm.bonus_writes_enabled', false)) {
            return false;
        }

        return DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $actor->getAuthIdentifier())
            ->whereIn('role_code', $roles)
            ->exists();
    }
}
