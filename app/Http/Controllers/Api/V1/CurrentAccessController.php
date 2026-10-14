<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\CompanyCapabilities;
use App\Domain\Identity\CompanyRole;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/me/access exposes *effective* memberships, without accepting
 * role codes from the client or returning other users' permissions.
 */
final class CurrentAccessController extends Controller
{
    public function __invoke(Request $request, CompanyCapabilities $capabilities): JsonResponse
    {
        $userId = $request->user()->getAuthIdentifier();
        $rows = DB::table('company_user_roles as membership')
            ->join('companies as company', 'company.id', '=', 'membership.company_id')
            ->where('membership.user_id', $userId)
            ->select('membership.company_id', 'company.name as company_name', 'membership.role_code')
            ->orderBy('company.id')
            ->get();

        $managerIds = $rows->filter(static fn (object $row): bool =>
            $row->role_code === CompanyRole::DepartmentManager->value)->pluck('company_id')->all();
        $scopes = $managerIds === [] ? collect() : DB::table('department_access_scopes as scope')
            ->join('departments as department', function ($join): void {
                $join->on('scope.department_id', '=', 'department.id')
                    ->on('scope.company_id', '=', 'department.company_id');
            })
            ->where('scope.user_id', $userId)
            ->whereIn('scope.company_id', $managerIds)
            ->select('scope.company_id', 'department.id', 'department.name')
            ->orderBy('department.name')
            ->get()->groupBy('company_id');

        $companies = $rows->map(static function (object $row) use ($scopes, $capabilities): ?array {
            $role = CompanyRole::tryFrom($row->role_code);
            if ($role === null) {
                // Unknown role strings must never inherit HR access.
                return null;
            }
            return [
                'company_id' => $row->company_id,
                'company_name' => $row->company_name,
                'role_code' => $role->value,
                'department_scopes' => $role === CompanyRole::DepartmentManager
                    ? $scopes->get($row->company_id, collect())->map(static fn ($department): array => [
                        'id' => $department->id, 'name' => $department->name,
                    ])->values()->all()
                    : [],
                'capabilities' => $capabilities->forRole($role),
            ];
        })->filter()->values()->all();

        return response()->json([
            'data' => ['companies' => $companies],
            'meta' => [
                'schema_version' => 1,
                'source' => 'server',
            ],
        ])->header('Cache-Control', 'private, no-store');
    }
}
