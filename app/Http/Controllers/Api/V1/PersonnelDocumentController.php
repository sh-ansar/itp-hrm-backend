<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class PersonnelDocumentController extends Controller
{
    public function index(Request $request, string $company, HrAuditWriter $audit): JsonResponse
    {
        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->value('role_code');
        abort_unless(in_array($role, ['company_admin', 'hr_specialist'], true), 403);

        $filters = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'employee_id' => ['sometimes', 'uuid'],
            'document_type' => ['sometimes', Rule::in(['order', 'contract', 'basis', 'other'])],
        ]);

        $query = DB::table('personnel_documents as d')
            ->join('employees as e', function ($join) use ($company): void {
                $join->on('d.employee_id', '=', 'e.id')->where('e.company_id', '=', $company);
            })
            ->where('d.company_id', $company);
        if (isset($filters['employee_id'])) {
            $query->where('d.employee_id', $filters['employee_id']);
        }
        if (isset($filters['document_type'])) {
            $query->where('d.document_type', $filters['document_type']);
        }

        // Never expose storage paths, disk identifiers or private file hashes.
        $page = $query->select(
            'd.id', 'd.employee_id', 'd.personnel_action_id',
            'd.document_type', 'd.document_number', 'd.title',
            'd.issued_on', 'd.status', 'd.revision', 'd.created_at',
            'e.personnel_number', 'e.first_name', 'e.last_name'
        )->orderByDesc('d.issued_on')->orderByDesc('d.created_at')->orderBy('d.id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        $audit->record($request->user(), $company, HrAuditAction::PersonnelDocumentsListed, null, [
            'returned_count' => count($page->items()),
        ]);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'document_writes_enabled' => false,
            ],
        ]);
    }
}
