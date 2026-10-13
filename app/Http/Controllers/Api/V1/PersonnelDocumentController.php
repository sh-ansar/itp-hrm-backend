<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

final class PersonnelDocumentController extends Controller
{
    public function store(Request $request, string $company, HrAuditWriter $audit): JsonResponse
    {
        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->value('role_code');
        abort_unless(in_array($role, ['company_admin', 'hr_specialist'], true)
            && (bool) config('hrm.document_writes_enabled', false), 404);

        $allowed = ['employee_id', 'personnel_action_id', 'document_type', 'document_number', 'title', 'issued_on'];
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected document fields.']);
        }

        $data = $request->validate([
            'employee_id' => ['required', 'uuid'],
            'personnel_action_id' => ['nullable', 'uuid'],
            'document_type' => ['required', Rule::in(['order', 'contract', 'basis', 'other'])],
            'document_number' => ['nullable', 'string', 'max:128'],
            'title' => ['required', 'string', 'max:255'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $employeeExists = DB::table('employees')
            ->where('id', $data['employee_id'])->where('company_id', $company)->exists();
        if (!$employeeExists) {
            throw ValidationException::withMessages(['employee_id' => 'Employee must belong to the selected company.']);
        }
        if (!empty($data['personnel_action_id'])) {
            $actionExists = DB::table('personnel_actions')
                ->where('id', $data['personnel_action_id'])
                ->where('company_id', $company)
                ->where('employee_id', $data['employee_id'])->exists();
            if (!$actionExists) {
                throw ValidationException::withMessages(['personnel_action_id' => 'Personnel action must belong to this employee and company.']);
            }
        }

        $id = (string) Str::uuid();
        DB::transaction(function () use ($request, $audit, $company, $id, $data): void {
            DB::table('personnel_documents')->insert([
                'id' => $id,
                'company_id' => $company,
                'employee_id' => $data['employee_id'],
                'personnel_action_id' => $data['personnel_action_id'] ?? null,
                'document_type' => $data['document_type'],
                'document_number' => $data['document_number'] ?? null,
                'title' => $data['title'],
                'issued_on' => $data['issued_on'] ?? null,
                'status' => 'draft',
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $audit->record($request->user(), $company, HrAuditAction::PersonnelDocumentDraftCreated, null, [
                'document_id' => $id,
                'employee_id' => $data['employee_id'],
                'document_type' => $data['document_type'],
            ]);
        });

        return response()->json(['data' => [
            'id' => $id,
            'employee_id' => $data['employee_id'],
            'document_type' => $data['document_type'],
            'status' => 'draft',
            'revision' => 1,
        ]], 201);
    }

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
        )->selectRaw('CASE WHEN d.storage_path IS NULL THEN 0 ELSE 1 END AS has_file')
            ->orderByDesc('d.issued_on')->orderByDesc('d.created_at')->orderBy('d.id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        // Boolean attachment availability only; never serialize disk/path/hash metadata.
        $page->getCollection()->transform(static function (object $row): object {
            $row->has_file = (bool) $row->has_file;
            return $row;
        });

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
                'document_writes_enabled' => (bool) config('hrm.document_writes_enabled', false),
            ],
        ]);
    }
}
