<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Personnel\DocumentMetadata;
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

        $allowed = ['employee_id', 'personnel_action_id', 'document_type', 'document_number', 'title', 'issued_on', 'description', 'tags', 'section_key'];
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
            'description' => ['nullable', 'string', 'max:2000'],
            'tags' => ['sometimes', 'array', 'max:12'],
            'tags.*' => ['required', 'string', 'max:40'],
            'section_key' => ['nullable', Rule::in(DocumentMetadata::SECTIONS)],
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
                'description' => trim((string) ($data['description'] ?? '')) ?: null,
                'tags' => json_encode(DocumentMetadata::tags($data['tags'] ?? []), JSON_THROW_ON_ERROR),
                'section_key' => $data['section_key'] ?? null,
                'status' => 'draft',
                'created_by_user_id' => $request->user()->getAuthIdentifier(),
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
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'tags' => DocumentMetadata::tags($data['tags'] ?? []),
            'section_key' => $data['section_key'] ?? null,
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
            'status' => ['sometimes', Rule::in(['draft', 'submitted', 'approved', 'rejected'])],
            'section_key' => ['sometimes', Rule::in(DocumentMetadata::SECTIONS)],
            'search' => ['sometimes', 'string', 'max:120'],
            'tag' => ['sometimes', 'string', 'max:40'],
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
        if (isset($filters['status'])) {
            $query->where('d.status', $filters['status']);
        }
        if (isset($filters['section_key'])) {
            $query->where('d.section_key', $filters['section_key']);
        }
        // Metadata search is company-scoped and only available to HR roles.
        // No PDF bytes, employee private records or object-store keys are indexed.
        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $term = mb_strtolower(trim($filters['search']));
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $query->where(static function ($q) use ($pattern): void {
                $q->whereLike('d.title', $pattern)
                    ->orWhereLike('d.document_number', $pattern)
                    ->orWhereLike('d.description', $pattern)
                    ->orWhereRaw('LOWER(CAST(d.tags AS TEXT)) LIKE ?', [$pattern]);
            });
        }
        if (isset($filters['tag'])) {
            $tag = mb_strtolower(trim($filters['tag']));
            $encoded = json_encode($tag, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $query->whereRaw('LOWER(CAST(d.tags AS TEXT)) LIKE ?', [
                '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $encoded).'%',
            ]);
        }

        // Never expose storage paths, disk identifiers or private file hashes.
        $page = $query->select(
            'd.id', 'd.employee_id', 'd.personnel_action_id',
            'd.document_type', 'd.document_number', 'd.title',
            'd.description', 'd.tags', 'd.section_key',
            'd.issued_on', 'd.status', 'd.revision', 'd.created_at',
            'd.created_by_user_id', 'd.submitted_by_user_id', 'd.submitted_at',
            'd.reviewed_by_user_id', 'd.reviewed_at', 'd.review_note',
            'd.employee_visible', 'd.employee_shared_at',
            'e.personnel_number', 'e.first_name', 'e.last_name'
        )->selectRaw('CASE WHEN d.storage_disk IS NOT NULL AND d.storage_path IS NOT NULL THEN 1 ELSE 0 END AS has_file')
            ->orderByDesc('d.issued_on')->orderByDesc('d.created_at')->orderBy('d.id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        // Boolean attachment availability only; never serialize disk/path/hash metadata.
        $page->getCollection()->transform(static function (object $row): object {
            $row->has_file = (bool) $row->has_file;
            $row->tags = DocumentMetadata::decode($row->tags);
            $row->employee_visible = filter_var($row->employee_visible, FILTER_VALIDATE_BOOLEAN);
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
                'order_approvals_enabled' => (bool) config('hrm.document_writes_enabled', false)
                    && (bool) config('hrm.order_approvals_enabled', false),
                'can_review_orders' => $role === 'company_admin'
                    && (bool) config('hrm.document_writes_enabled', false)
                    && (bool) config('hrm.order_approvals_enabled', false),
            ],
        ]);
    }

    /** Update descriptive metadata only; never grants PDF access or alters issuance. */
    public function metadata(Request $request, string $company, string $document, HrAuditWriter $audit): JsonResponse
    {
        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())->value('role_code');
        abort_unless(in_array($role, ['company_admin', 'hr_specialist'], true)
            && (bool) config('hrm.document_writes_enabled', false), 404);

        $allowed = ['revision', 'title', 'description', 'tags', 'section_key'];
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected document metadata fields.']);
        }
        $input = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'tags' => ['sometimes', 'array', 'max:12'],
            'tags.*' => ['required', 'string', 'max:40'],
            'section_key' => ['sometimes', 'nullable', Rule::in(DocumentMetadata::SECTIONS)],
        ]);
        if (array_diff(array_keys($input), ['revision']) === []) {
            throw ValidationException::withMessages(['request' => 'At least one metadata field is required.']);
        }
        return DB::transaction(function () use ($request, $company, $document, $input, $audit): JsonResponse {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $record = DB::table('personnel_documents as d')
                ->join('employees as e', 'e.id', '=', 'd.employee_id')
                ->where('d.id', $document)->where('d.company_id', $company)
                ->where('e.company_id', $company)
                ->lockForUpdate()->first(['d.id', 'd.employee_id', 'd.revision']);
            abort_unless($record, 404);
            abort_unless((int) $record->revision === (int) $input['revision'], 409,
                'Document metadata changed; reload before saving.');
            $values = [];
            if (array_key_exists('title', $input)) $values['title'] = trim($input['title']);
            if (array_key_exists('description', $input)) {
                $values['description'] = trim((string) ($input['description'] ?? '')) ?: null;
            }
            if (array_key_exists('tags', $input)) {
                $values['tags'] = json_encode(DocumentMetadata::tags($input['tags']), JSON_THROW_ON_ERROR);
            }
            if (array_key_exists('section_key', $input)) $values['section_key'] = $input['section_key'];
            $updated = DB::table('personnel_documents')
                ->where('id', $document)->where('company_id', $company)
                ->where('revision', $record->revision)
                ->update($values + ['revision' => (int) $record->revision + 1, 'updated_at' => now()]);
            abort_unless($updated === 1, 409);
            $audit->record($request->user(), $company, HrAuditAction::PersonnelDocumentMetadataUpdated, null, [
                'document_id' => $document, 'employee_id' => $record->employee_id,
                'changed_fields' => array_keys($values), 'revision' => (int) $record->revision + 1,
            ]);
            $fresh = DB::table('personnel_documents')->where('company_id', $company)
                ->where('id', $document)->first(['id', 'title', 'description', 'tags', 'section_key', 'revision']);
            return response()->json(['data' => [
                'id' => $fresh->id, 'title' => $fresh->title,
                'description' => $fresh->description, 'tags' => DocumentMetadata::decode($fresh->tags),
                'section_key' => $fresh->section_key, 'revision' => (int) $fresh->revision,
            ]])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    /**
     * Explicit HR distribution, never automatic on upload.
     * This is a PDF visibility approval, NOT a signature or legal issuance.
     */
    public function employeeAccess(Request $request, string $company, string $document, HrAuditWriter $audit): JsonResponse
    {
        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->value('role_code');
        abort_unless(in_array($role, ['company_admin', 'hr_specialist'], true)
            && (bool) config('hrm.document_writes_enabled', false), 404);

        if (array_diff(array_keys($request->all()), ['revision', 'employee_visible']) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected document access fields.']);
        }
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'employee_visible' => ['required', 'boolean'],
        ]);

        return DB::transaction(function () use ($request, $company, $document, $audit, $data): JsonResponse {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $row = DB::table('personnel_documents as d')
                ->join('employees as e', function ($join) use ($company): void {
                    $join->on('e.id', '=', 'd.employee_id')->where('e.company_id', $company);
                })
                ->where('d.company_id', $company)->where('d.id', $document)
                ->select('d.*')->first();
            abort_unless($row, 404);
            abort_unless((int) $row->revision === (int) $data['revision'], 409);

            $visible = (bool) $data['employee_visible'];
            if ($visible) {
                // Internal HR bases and miscellaneous records are never publishable via this endpoint.
                abort_unless(in_array($row->document_type, ['order', 'contract'], true), 422);
                // When reviewed orders are active, no newly shared draft/pending
                // order may be released to the employee. Legacy shares stay readable
                // until separately reconciled or revoked by HR.
                if ($row->document_type === 'order'
                    && config('hrm.order_approvals_enabled', false)) {
                    abort_unless($row->status === 'approved', 422,
                        'Approve the order internally before releasing it to the employee.');
                }
                // Only validated private PDFs can be explicitly released after HR review.
                abort_unless($row->storage_disk === 'minio'
                    && $row->content_type === 'application/pdf'
                    && is_string($row->storage_path)
                    && str_starts_with($row->storage_path, 'personnel-documents/'.$company.'/'.$document.'/')
                    && preg_match('/^[a-z0-9\\/-]+\\.pdf$/', $row->storage_path) === 1
                    && (int) $row->byte_size > 0
                    && is_string($row->sha256) && preg_match('/^[0-9a-f]{64}$/', $row->sha256) === 1, 422);
            }
            if (filter_var($row->employee_visible, FILTER_VALIDATE_BOOLEAN) === $visible) {
                return response()->json(['data' => [
                    'id' => $document, 'employee_visible' => $visible,
                    'employee_shared_at' => $row->employee_shared_at,
                    'revision' => (int) $row->revision,
                ]])->header('Cache-Control', 'private, no-store');
            }

            $updated = DB::table('personnel_documents')
                ->where('id', $document)->where('company_id', $company)->where('revision', $row->revision)
                ->update([
                    'employee_visible' => $visible,
                    'employee_shared_at' => $visible ? now() : null,
                    'revision' => (int) $row->revision + 1,
                    'updated_at' => now(),
                ]);
            abort_unless($updated === 1, 409);
            $audit->record($request->user(), $company,
                $visible ? HrAuditAction::PersonnelDocumentSharedWithEmployee
                    : HrAuditAction::PersonnelDocumentEmployeeAccessRevoked, null,
                ['document_id' => $document, 'employee_id' => $row->employee_id]);
            $sharedAt = DB::table('personnel_documents')->where('id', $document)->value('employee_shared_at');

            return response()->json(['data' => [
                'id' => $document, 'employee_visible' => $visible,
                'employee_shared_at' => $sharedAt, 'revision' => (int) $row->revision + 1,
            ]])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

}
