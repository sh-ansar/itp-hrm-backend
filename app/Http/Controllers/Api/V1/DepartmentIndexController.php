<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Auth\Access\AuthorizationException;

final class DepartmentIndexController extends Controller
{
    public function __invoke(Request $request, string $company): JsonResponse
    {
        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->value('role_code');

        if (!in_array($role, ['company_admin', 'hr_specialist', 'department_manager'], true)) {
            throw new AuthorizationException('No permission to read company departments.');
        }

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DB::table('departments as d')
            ->leftJoin('employees as manager', function ($join) use ($company): void {
                $join->on('manager.id', '=', 'd.manager_employee_id')
                    ->where('manager.company_id', $company);
            })
            ->where('d.company_id', $company)
            ->select('d.id', 'd.parent_id', 'd.name', 'd.revision',
                'd.manager_employee_id',
                'manager.last_name as manager_last_name',
                'manager.first_name as manager_first_name',
                'manager.middle_name as manager_middle_name');

        // Managers may only inspect explicitly assigned departments; no inherited scopes.
        if ($role === 'department_manager') {
            $query->whereIn('d.id', DB::table('department_access_scopes')
                ->select('department_id')
                ->where('company_id', $company)
                ->where('user_id', $request->user()->getAuthIdentifier()));
        }

        $page = $query->orderBy('d.name')->orderBy('d.id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        $rows = collect($page->items())->map(function ($row): object {
            $row->manager_employee_name = $row->manager_employee_id === null
                ? null
                : trim(implode(' ', array_filter([
                    $row->manager_last_name, $row->manager_first_name, $row->manager_middle_name,
                ], static fn ($part) => is_string($part) && $part !== '')));
            unset($row->manager_last_name, $row->manager_first_name, $row->manager_middle_name);
            return $row;
        })->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'manager_assignment_supported' => true,
                'department_writes_enabled' => in_array($role, ['company_admin', 'hr_specialist'], true)
                    && (bool) config('hrm.personnel_writes_enabled', false),
            ],
        ]);
    }
}
