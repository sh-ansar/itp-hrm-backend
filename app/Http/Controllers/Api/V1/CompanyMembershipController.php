<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\CompanyRole;
use App\Domain\Identity\CompanyRoleAssignments;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CompanyMembershipController extends Controller
{
    public function upsert(
        Request $request,
        string $company,
        int $member,
        CompanyRoleAssignments $roles,
    ): JsonResponse {
        $this->authorizeManagement($request, $company, $roles);

        $validated = $request->validate([
            'role_code' => ['required', Rule::in([
                CompanyRole::HrSpecialist->value,
                CompanyRole::DepartmentManager->value,
                CompanyRole::Employee->value,
            ])],
            'department_ids' => ['sometimes', 'array', 'max:100'],
            'department_ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        $departmentIds = $validated['department_ids'] ?? [];

        if ($validated['role_code'] !== CompanyRole::DepartmentManager->value && $departmentIds !== []) {
            throw ValidationException::withMessages([
                'department_ids' => 'Department scopes are only valid for department managers.',
            ]);
        }

        return response()->json([
            'data' => $roles->assign(
                $request->user(),
                $company,
                $member,
                CompanyRole::from($validated['role_code']),
                $departmentIds,
            ),
        ]);
    }

    public function destroy(
        Request $request,
        string $company,
        int $member,
        CompanyRoleAssignments $roles,
    ): Response {
        $this->authorizeManagement($request, $company, $roles);
        $roles->revoke($request->user(), $company, $member);

        return response()->noContent();
    }

    private function authorizeManagement(
        Request $request,
        string $company,
        CompanyRoleAssignments $roles,
    ): void {
        // This experimental admin surface is deliberately disabled in all
        // environments until the business access matrix and CSRF topology pass review.
        abort_unless((bool) config('hrm.role_management_enabled', false), 404);
        $roles->assertCompanyAdmin($request->user(), $company);
    }
}
