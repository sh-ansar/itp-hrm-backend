<?php

namespace App\Http\Controllers\Api\V1;

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

        // Visibility is membership-derived; never enumerate the global companies table.
        // Employee-only memberships do not confer permission to browse organization data.
        $page = DB::table('companies as c')
            ->join('company_user_roles as r', 'r.company_id', '=', 'c.id')
            ->where('r.user_id', $request->user()->getAuthIdentifier())
            ->whereIn('r.role_code', ['company_admin', 'hr_specialist', 'department_manager'])
            ->select('c.id')
            ->orderBy('c.id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
