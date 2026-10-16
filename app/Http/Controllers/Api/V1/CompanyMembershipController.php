<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\CompanyRole;
use App\Domain\Identity\CompanyRoleAssignments;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CompanyMembershipController extends Controller
{
    public function index(
        Request $request,
        string $company,
        CompanyRoleAssignments $roles,
    ): JsonResponse {
        $this->authorizeManagement($request, $company, $roles);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:120'],
        ]);

        $query = DB::table('company_user_roles as membership')
            ->join('users as user', 'user.id', '=', 'membership.user_id')
            ->where('membership.company_id', $company)
            ->select(
                'user.id',
                'user.name',
                'user.email',
                'user.account_status',
                'membership.role_code',
                'membership.updated_at',
            );

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $pattern = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(static function ($scope) use ($pattern): void {
                $scope->whereLike('user.name', $pattern)
                    ->orWhereLike('user.email', $pattern);
            });
        }

        $page = $query
            ->orderBy('user.name')
            ->orderBy('user.id')
            ->paginate((int) ($validated['per_page'] ?? 50));

        $userIds = collect($page->items())->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $scopes = $userIds === []
            ? collect()
            : DB::table('department_access_scopes as scope')
                ->join('departments as department', function ($join): void {
                    $join->on('department.id', '=', 'scope.department_id')
                        ->on('department.company_id', '=', 'scope.company_id');
                })
                ->where('scope.company_id', $company)
                ->whereIn('scope.user_id', $userIds)
                ->select('scope.user_id', 'department.id', 'department.name')
                ->orderBy('department.name')
                ->orderBy('department.id')
                ->get()
                ->groupBy('user_id');

        $page->getCollection()->transform(static function (object $row) use ($scopes): array {
            return [
                'user_id' => (int) $row->id,
                'name' => $row->name,
                'email' => $row->email,
                'status' => $row->account_status,
                'role_code' => $row->role_code,
                'department_scopes' => ($scopes->get($row->id, collect()))
                    ->map(static fn (object $scope): array => [
                        'id' => $scope->id,
                        'name' => $scope->name,
                    ])
                    ->values()
                    ->all(),
                'updated_at' => $row->updated_at,
            ];
        });

        $roleActions = [
            \App\Domain\Audit\HrAuditAction::RoleGranted->value,
            \App\Domain\Audit\HrAuditAction::RoleChanged->value,
            \App\Domain\Audit\HrAuditAction::RoleRevoked->value,
        ];
        $audit = DB::table('hr_audit_events as audit')
            ->leftJoin('users as actor', 'actor.id', '=', 'audit.actor_user_id')
            ->leftJoin('users as subject', 'subject.id', '=', 'audit.subject_user_id')
            ->where('audit.company_id', $company)
            ->whereIn('audit.action', $roleActions)
            ->select(
                'audit.id',
                'audit.action',
                'audit.created_at',
                'actor.name as actor_name',
                'subject.name as subject_name',
            )
            ->orderByDesc('audit.created_at')
            ->orderByDesc('audit.id')
            ->limit(100)
            ->get()
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'action' => $row->action,
                'actor' => $row->actor_name,
                'subject' => $row->subject_name,
                'created_at' => $row->created_at,
            ])
            ->all();

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'role_management_enabled' => (bool) config('hrm.role_management_enabled', false),
            ],
            'audit' => $audit,
        ]);
    }

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
