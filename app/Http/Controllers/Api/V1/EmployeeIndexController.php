<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Personnel\EmployeeVisibility;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmployeeIndexController extends Controller
{
    public function __invoke(
        Request $request,
        string $company,
        EmployeeVisibility $visibility,
        HrAuditWriter $audit,
    ): JsonResponse {
        // Authorization must happen before query or audit of a successful read.
        $query = $visibility->visibleFor($request->user(), $company);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $query
            ->orderBy('employees.last_name')
            ->orderBy('employees.first_name')
            ->orderBy('employees.id')
            ->paginate((int) ($validated['per_page'] ?? 25));

        // Synchronous, fail-closed audit. No employee identities are copied
        // into the audit metadata. A failed audit prevents a 200 response.
        $audit->record(
            $request->user(),
            $company,
            HrAuditAction::EmployeesListed,
            null,
            [
                'current_page' => $page->currentPage(),
                'returned_count' => count($page->items()),
            ],
        );

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
