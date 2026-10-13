<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PositionIndexController extends Controller
{
    public function __invoke(Request $request, string $company): JsonResponse
    {
        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->value('role_code');

        // A manager's scope is department-based, while positions currently
        // belong to companies. Never expose a global position catalogue to managers.
        if (!in_array($role, ['company_admin', 'hr_specialist'], true)) {
            throw new AuthorizationException('No permission to read company positions.');
        }

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = DB::table('positions')
            ->where('company_id', $company)
            ->select('id', 'name', 'revision')
            ->orderBy('name')->orderBy('id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'position_writes_enabled' => in_array($role, ['company_admin', 'hr_specialist'], true)
                    && (bool) config('hrm.personnel_writes_enabled', false),
            ],
        ]);
    }
}
