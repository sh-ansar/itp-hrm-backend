<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Internal document review, NOT a qualified signature or the execution of a
 * personnel action. Executed hire/transfer/termination records remain separate.
 */
final class PersonnelOrderApprovalController extends Controller
{
    private function authorize(Request $request, string $company, bool $review): void
    {
        abort_unless(config('hrm.document_writes_enabled', false)
            && config('hrm.order_approvals_enabled', false), 404);
        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())->value('role_code');

        // HR prepares and submits; only company administrators may independently review.
        abort_unless($review ? $role === 'company_admin'
            : in_array($role, ['hr_specialist', 'company_admin'], true), 404);
    }

    private function order(string $company, string $document): object
    {
        $row = DB::table('personnel_documents as d')
            ->join('employees as e', function ($join) use ($company): void {
                $join->on('e.id', '=', 'd.employee_id')->where('e.company_id', $company);
            })
            ->where('d.company_id', $company)->where('d.id', $document)
            ->where('d.document_type', 'order')
            ->select('d.*')->first();
        abort_unless($row, 404);
        return $row;
    }

    private function rejectUnexpected(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected order approval fields.']);
        }
    }

    /** Only company-scoped, stored and checksum-registered private PDFs may enter review. */
    private function assertPrivatePdf(object $row, string $company, string $document): void
    {
        $path = $row->storage_path;
        if ($row->storage_disk !== 'minio'
            || $row->content_type !== 'application/pdf'
            || !is_string($path)
            || !str_starts_with($path, 'personnel-documents/'.$company.'/'.$document.'/')
            || preg_match('/^[a-z0-9\/-]+\.pdf$/', $path) !== 1
            || (int) $row->byte_size < 1
            || !is_string($row->sha256)
            || preg_match('/^[0-9a-f]{64}$/', $row->sha256) !== 1) {
            throw ValidationException::withMessages([
                'file' => 'Attach a validated private PDF before submitting this order.',
            ]);
        }
    }

    public function submit(Request $request, string $company, string $document, HrAuditWriter $audit): JsonResponse
    {
        $this->authorize($request, $company, false);
        $this->rejectUnexpected($request, ['revision']);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
        ]);

        return DB::transaction(function () use ($request, $company, $document, $data, $audit): JsonResponse {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $row = $this->order($company, $document);
            abort_unless((int) $row->revision === (int) $data['revision'], 409,
                'This order has changed. Reload the register.');
            abort_unless($row->status === 'draft', 409);
            // Legacy documents must not gain an invented author or a review history.
            abort_unless($row->created_by_user_id !== null, 422,
                'A legacy draft requires explicit migration review.');
            abort_unless((int) $row->created_by_user_id === (int) $request->user()->getAuthIdentifier(), 403,
                'Only the document author can submit the draft.');
            abort_if((bool) $row->employee_visible, 409, 'Revoke employee access before submitting.');
            if (!$row->document_number || !$row->issued_on) {
                throw ValidationException::withMessages([
                    'order' => 'Enter the order number and document date before submitting.',
                ]);
            }
            $this->assertPrivatePdf($row, $company, $document);
            $affected = DB::table('personnel_documents')
                ->where('id', $document)->where('company_id', $company)
                ->where('revision', $row->revision)->where('status', 'draft')
                ->update([
                    'status' => 'submitted',
                    'submitted_by_user_id' => $request->user()->getAuthIdentifier(),
                    'submitted_at' => now(),
                    'revision' => (int) $row->revision + 1,
                    'updated_at' => now(),
                ]);
            abort_unless($affected === 1, 409);
            $audit->record($request->user(), $company, HrAuditAction::PersonnelOrderSubmitted, null, [
                'document_id' => $document, 'employee_id' => $row->employee_id,
            ]);
            return response()->json(['data' => [
                'id' => $document, 'status' => 'submitted',
                'revision' => (int) $row->revision + 1,
            ]])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function decision(Request $request, string $company, string $document, HrAuditWriter $audit): JsonResponse
    {
        $this->authorize($request, $company, true);
        $this->rejectUnexpected($request, ['revision', 'decision', 'comment']);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'in:approved,rejected'],
            'comment' => ['nullable', 'string', 'min:5', 'max:500', 'required_if:decision,rejected'],
        ]);

        return DB::transaction(function () use ($request, $company, $document, $data, $audit): JsonResponse {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $row = $this->order($company, $document);
            abort_unless((int) $row->revision === (int) $data['revision'], 409,
                'This order has changed. Reload the register.');
            abort_unless($row->status === 'submitted', 409,
                'Only orders awaiting review can be approved or rejected.');

            $actor = $request->user()->getAuthIdentifier();
            // Four-eyes: no self approval, including a separate HR account
            // currently mapped to the same person as the subject employee.
            abort_if((int) $row->created_by_user_id === (int) $actor
                || (int) $row->submitted_by_user_id === (int) $actor, 403,
                'The submitting user cannot review their own order.');
            $ownSubject = DB::table('employee_user_links')
                ->where('company_id', $company)->where('user_id', $actor)
                ->where('employee_id', $row->employee_id)->exists();
            abort_if($ownSubject, 403,
                'An employee cannot approve or reject an order about their own record.');

            // File and metadata must still be intact when approving.
            if ($data['decision'] === 'approved') {
                $this->assertPrivatePdf($row, $company, $document);
                abort_unless($row->document_number && $row->issued_on, 422);
            }
            abort_if((bool) $row->employee_visible, 409,
                'Employee access must not be active during the review.');
            $next = $data['decision'];
            $affected = DB::table('personnel_documents')
                ->where('id', $document)->where('company_id', $company)
                ->where('revision', $row->revision)->where('status', 'submitted')
                ->update([
                    'status' => $next, 'reviewed_by_user_id' => $actor,
                    'reviewed_at' => now(),
                    'review_note' => trim((string) ($data['comment'] ?? '')) ?: null,
                    'revision' => (int) $row->revision + 1, 'updated_at' => now(),
                ]);
            abort_unless($affected === 1, 409);
            $audit->record($request->user(), $company,
                $next === 'approved' ? HrAuditAction::PersonnelOrderApproved
                    : HrAuditAction::PersonnelOrderRejected, null, [
                        'document_id' => $document, 'employee_id' => $row->employee_id,
                    ]);
            return response()->json(['data' => [
                'id' => $document, 'status' => $next,
                'revision' => (int) $row->revision + 1,
            ]])->header('Cache-Control', 'private, no-store');
        }, 3);
    }
}
