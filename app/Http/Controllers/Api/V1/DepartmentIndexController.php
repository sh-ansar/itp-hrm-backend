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
            ->where('d.company_id', $company)
            ->select('d.id', 'd.parent_id', 'd.name', 'd.revision');

        // Managers may only inspect explicitly assigned departments; no inherited scopes.
        if ($role === 'department_manager') {
            $query->whereIn('d.id', DB::table('department_access_scopes')
                ->select('department_id')
                ->where('company_id', $company)
                ->where('user_id', $request->user()->getAuthIdentifier()));
        }

        $page = $query->orderBy('d.name')->orderBy('d.id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'department_writes_enabled' => in_array($role, ['company_admin', 'hr_specialist'], true)
                    && (bool) config('hrm.personnel_writes_enabled', false),
            ],
        ]);
    }
}
