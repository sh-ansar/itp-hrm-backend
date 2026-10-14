<?php

namespace App\Domain\Worktime;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Explicit server-side company permissions. Vue must never be the authority.
 *
 * Existing role assignments are scoped to exactly one company. Calendar
 * edits affect everyone, so only a company_admin can make them.
 */
final class WorkforceAuthorization
{
    public function canManageLeaveRequests(User $actor, string $companyId): bool
    {
        return $this->companyRoleIs($actor, $companyId, ['company_admin', 'hr_specialist']);
    }

    public function canApproveLeaveRequests(User $actor, string $companyId): bool
    {
        return $this->companyRoleIs($actor, $companyId, ['company_admin']);
    }

    private function companyRoleIs(User $actor, string $companyId, array $allowed): bool
    {
        if (! config('hrm.leave_writes_enabled', false)) {
            return false;
        }

        return DB::table('company_user_roles')
            ->where('company_id', $companyId)
            ->where('user_id', $actor->getAuthIdentifier())
            ->whereIn('role_code', $allowed)
            ->exists();
    }

    public function canEditCalendar(User $actor, string $companyId): bool
    {
        if (! config('hrm.worktime_writes_enabled', false)) {
            return false;
        }

        return DB::table('company_user_roles')
            ->where('company_id', $companyId)
            ->where('user_id', $actor->getAuthIdentifier())
            ->where('role_code', 'company_admin')
            ->exists();
    }
}
