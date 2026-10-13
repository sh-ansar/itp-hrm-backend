<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\CompanyRole;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CompanyIndexController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $userId = $request->user()->getAuthIdentifier();

        // Visibility is membership-derived; never enumerate the global companies table.
        // Employee-only memberships do not confer permission to browse organization data.
        $page = DB::table('companies as c')
            ->join('company_user_roles as r', 'r.company_id', '=', 'c.id')
            ->where('r.user_id', $userId)
            ->whereIn('r.role_code', [
                CompanyRole::CompanyAdmin->value,
                CompanyRole::HrSpecialist->value,
                CompanyRole::DepartmentManager->value,
            ])
            ->select('c.id', 'c.name', 'r.role_code')
            ->orderBy('c.id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        $companyIds = collect($page->items())->pluck('id')->all();
        $scopes = $companyIds === []
            ? collect()
            : DB::table('department_access_scopes as s')
                ->join('departments as d', function ($join): void {
                    $join->on('d.id', '=', 's.department_id')
                        ->on('d.company_id', '=', 's.company_id');
                })
                ->where('s.user_id', $userId)
                ->whereIn('s.company_id', $companyIds)
                ->select('s.company_id', 'd.id', 'd.name')
                ->orderBy('d.name')
                ->orderBy('d.id')
                ->get()
                ->groupBy('company_id');

        $page->getCollection()->transform(static function (object $row) use ($scopes): array {
            $departmentScopes = $row->role_code === CompanyRole::DepartmentManager->value
                ? ($scopes->get($row->id, collect()))
                    ->map(static fn (object $scope): array => [
                        'id' => $scope->id,
                        'name' => $scope->name,
                    ])
                    ->values()
                    ->all()
                : [];

            return [
                'id' => $row->id,
                'name' => $row->name,
                'role_code' => $row->role_code,
                'department_scopes' => $departmentScopes,
            ];
        });

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'role_management_enabled' => (bool) config('hrm.role_management_enabled', false),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
