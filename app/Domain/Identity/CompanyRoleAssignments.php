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
        ?string $customRoleId = null,
    ): array {
        if ($role === CompanyRole::CompanyAdmin) {
            throw new AuthorizationException('Admin grants are not available via API.');
        }

        return DB::transaction(function () use ($actor, $companyId, $targetUserId, $role, $departmentIds, $customRoleId): array {
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
                ->first(['role_code', 'custom_role_id']);

            if ($current?->role_code === CompanyRole::CompanyAdmin->value) {
                throw new AuthorizationException('Admin memberships are immutable via API.');
            }

            if ($customRoleId !== null) {
                abort_unless(config('hrm.custom_role_templates_enabled', false), 404);
                $template = DB::table('company_role_templates')
                    ->where('company_id', $companyId)->where('id', $customRoleId)
                    ->lockForUpdate()->first(['id', 'base_role', 'status']);
                if ($template === null || $template->status !== 'active' || $template->base_role !== $role->value) {
                    throw ValidationException::withMessages([
                        'custom_role_id' => 'Role must be active, belong to this company, and match its base role.',
                    ]);
                }
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
            if ($current !== null && $current->role_code === $role->value
                && $current->custom_role_id === $customRoleId && $existing === $wanted) {
                return $this->assignedResponse($targetUserId, $role, $wanted, $customRoleId);
            }

            $at = now();
            if ($current === null) {
                DB::table('company_user_roles')->insert([
                    'user_id' => $targetUserId,
                    'company_id' => $companyId,
                    'role_code' => $role->value,
                    'custom_role_id' => $customRoleId,
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            } elseif ($current->role_code !== $role->value || $current->custom_role_id !== $customRoleId) {
                DB::table('company_user_roles')
                    ->where('user_id', $targetUserId)
                    ->where('company_id', $companyId)
                    ->update(['role_code' => $role->value, 'custom_role_id' => $customRoleId, 'updated_at' => $at]);
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
                    'previous_template_id' => $current?->custom_role_id,
                    'new_template_id' => $customRoleId,
                    'previous_department_ids' => $existing,
                    'new_department_ids' => $wanted,
                ],
            );

            return $this->assignedResponse($targetUserId, $role, $wanted, $customRoleId);
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

            // A revoked membership must not retain department grants that could
            // become effective again on a subsequent role assignment.
            $previousDepartmentIds = DB::table('department_access_scopes')
                ->where('user_id', $targetUserId)
                ->where('company_id', $companyId)
                ->pluck('department_id')
                ->all();

            DB::table('department_access_scopes')
                ->where('user_id', $targetUserId)
                ->where('company_id', $companyId)
                ->delete();

            DB::table('company_user_roles')
                ->where('user_id', $targetUserId)
                ->where('company_id', $companyId)
                ->delete();

            $this->audit->record(
                $actor,
                $companyId,
                HrAuditAction::RoleRevoked,
                $targetUserId,
                [
                    'previous_role' => $current->role_code,
                    'previous_department_ids' => $previousDepartmentIds,
                ],
            );
        }, 3);
    }

    private function assignedResponse(int $targetUserId, CompanyRole $role, array $wanted, ?string $customRoleId): array
    {
        $result = ['user_id' => $targetUserId, 'role_code' => $role->value, 'department_ids' => $wanted];
        if ($customRoleId !== null) $result['custom_role_id'] = $customRoleId;
        return $result;
    }

    private function assertNotSelf(User $actor, int $targetUserId): void
    {
        if ((int) $actor->getAuthIdentifier() === $targetUserId) {
            throw new AuthorizationException('Changing your own membership is not permitted.');
        }
    }
}
