<?php

namespace App\Domain\Identity;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CompanyRoleAssignments
{
    public function __construct(private readonly HrAuditWriter $audit)
    {
    }

    public function assertCompanyAdmin(User $actor, string $companyId): void
    {
        $role = DB::table('company_user_roles')
            ->where('user_id', $actor->getAuthIdentifier())
            ->where('company_id', $companyId)
            ->value('role_code');

        if ($role !== CompanyRole::CompanyAdmin->value) {
            throw new AuthorizationException('Role administration is not permitted.');
        }
    }

    /**
     * Admin roles can only be bootstrapped by a separately controlled
     * administrative process; HTTP can grant lower-privilege roles only.
     */
    public function assign(
        User $actor,
        string $companyId,
        int $targetUserId,
        CompanyRole $role,
        array $departmentIds,
    ): array {
        if ($role === CompanyRole::CompanyAdmin) {
            throw new AuthorizationException('Admin grants are not available via API.');
        }

        return DB::transaction(function () use ($actor, $companyId, $targetUserId, $role, $departmentIds): array {
            $this->assertCompanyAdmin($actor, $companyId);
            $this->assertNotSelf($actor, $targetUserId);

            // Lock the target user so two grants/revocations for this identity
            // are serialized even if the membership does not exist yet.
            $target = DB::table('users')
                ->where('id', $targetUserId)
                ->lockForUpdate()
                ->first(['id']);

            if ($target === null) {
                abort(404);
            }

            $current = DB::table('company_user_roles')
                ->where('user_id', $targetUserId)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first(['role_code']);

            if ($current?->role_code === CompanyRole::CompanyAdmin->value) {
                throw new AuthorizationException('Admin memberships are immutable via API.');
            }

            $wanted = $role === CompanyRole::DepartmentManager ? array_values($departmentIds) : [];
            sort($wanted, SORT_STRING);

            if (count($wanted) > 0) {
                $matched = DB::table('departments')
                    ->where('company_id', $companyId)
                    ->whereIn('id', $wanted)
                    ->count();

                if ($matched !== count($wanted)) {
                    throw ValidationException::withMessages([
                        'department_ids' => 'Every department must belong to the requested company.',
                    ]);
                }
            }

            $existing = DB::table('department_access_scopes')
                ->where('user_id', $targetUserId)
                ->where('company_id', $companyId)
                ->pluck('department_id')
                ->all();
            sort($existing, SORT_STRING);

            // PUT is idempotent; repeated identical requests do not create
            // duplicate rows, timestamps or misleading audit events.
            if ($current !== null && $current->role_code === $role->value && $existing === $wanted) {
                return ['user_id' => $targetUserId, 'role_code' => $role->value, 'department_ids' => $wanted];
            }

            $at = now();
            if ($current === null) {
                DB::table('company_user_roles')->insert([
                    'user_id' => $targetUserId,
                    'company_id' => $companyId,
                    'role_code' => $role->value,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            } elseif ($current->role_code !== $role->value) {
                DB::table('company_user_roles')
                    ->where('user_id', $targetUserId)
                    ->where('company_id', $companyId)
                    ->update(['role_code' => $role->value, 'updated_at' => $at]);
            }

            DB::table('department_access_scopes')
                ->where('user_id', $targetUserId)
                ->where('company_id', $companyId)
                ->delete();

            foreach ($wanted as $departmentId) {
                DB::table('department_access_scopes')->insert([
                    'user_id' => $targetUserId,
                    'company_id' => $companyId,
                    'department_id' => $departmentId,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }

            $this->audit->record(
                $actor,
                $companyId,
                $current === null ? HrAuditAction::RoleGranted : HrAuditAction::RoleChanged,
                $targetUserId,
                [
                    'previous_role' => $current?->role_code,
                    'new_role' => $role->value,
                    'previous_department_ids' => $existing,
                    'new_department_ids' => $wanted,
                ],
            );

            return ['user_id' => $targetUserId, 'role_code' => $role->value, 'department_ids' => $wanted];
        }, 3);
    }

    public function revoke(User $actor, string $companyId, int $targetUserId): void
    {
        DB::transaction(function () use ($actor, $companyId, $targetUserId): void {
            $this->assertCompanyAdmin($actor, $companyId);
            $this->assertNotSelf($actor, $targetUserId);

            $target = DB::table('users')
                ->where('id', $targetUserId)
                ->lockForUpdate()
                ->first(['id']);

            if ($target === null) {
                abort(404);
            }

            $current = DB::table('company_user_roles')
                ->where('user_id', $targetUserId)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first(['role_code']);

            if ($current === null) {
                abort(404);
            }
            if ($current->role_code === CompanyRole::CompanyAdmin->value) {
                throw new AuthorizationException('Admin memberships are immutable via API.');
            }

            DB::table('company_user_roles')
                ->where('user_id', $targetUserId)
                ->where('company_id', $companyId)
                ->delete();

            $this->audit->record(
                $actor,
                $companyId,
                HrAuditAction::RoleRevoked,
                $targetUserId,
                ['previous_role' => $current->role_code],
            );
        }, 3);
    }

    private function assertNotSelf(User $actor, int $targetUserId): void
    {
        if ((int) $actor->getAuthIdentifier() === $targetUserId) {
            throw new AuthorizationException('Changing your own membership is not permitted.');
        }
    }
}
