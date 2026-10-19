<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private and explicitly role-scoped supporting evidence for protected leave.
 * No public object keys, signed URLs, uploaded filenames, diagnoses or
 * employee identifiers are exposed in its API responses or audit context.
 */
final class ProtectedLeaveEvidenceController extends Controller
{
    private function requestFor(Request $request, string $company, string $id, bool $hrOnly = false): object
    {
        $actor = $request->user()->getAuthIdentifier();
        $role = DB::table('company_user_roles')->where('company_id', $company)
            ->where('user_id', $actor)->value('role_code');
        abort_unless(in_array($role,
            ['company_admin', 'hr_specialist', 'department_manager', 'employee'], true), 404);

        $record = DB::table('leave_requests as lr')
            ->join('leave_types as lt', function ($join) use ($company): void {
                $join->on('lt.id', '=', 'lr.leave_type_id')->where('lt.company_id', $company);
            })
            ->join('employees as e', function ($join) use ($company): void {
                $join->on('e.id', '=', 'lr.employee_id')->where('e.company_id', $company);
            })
            ->where('lr.company_id', $company)->where('lr.id', $id)
            ->select('lr.*', 'lt.category')->first();
        abort_unless($record && in_array($record->category, ['maternity', 'childcare', 'medical'], true), 404);

        $hr = in_array($role, ['company_admin', 'hr_specialist'], true);
        $linked = DB::table('employee_user_links')
            ->where('company_id', $company)->where('user_id', $actor)
            ->where('employee_id', $record->employee_id)->exists();
        $owner = $linked && (int) $record->created_by_user_id === (int) $actor;
        abort_unless($hrOnly ? $hr : ($hr || $owner), 404);
        return $record;
    }

    private function evidence(string $company, string $requestId): ?object
    {
        return DB::table('leave_request_evidence')
            ->where('company_id', $company)->where('leave_request_id', $requestId)->first();
    }

    public function upload(Request $request, string $company, string $leaveRequest, HrAuditWriter $audit): JsonResponse
    {
        abort_unless(config('hrm.leave_writes_enabled', false)
            && config('hrm.protected_leave_evidence_enabled', false), 404);
        $record = $this->requestFor($request, $company, $leaveRequest);
        abort_unless(in_array($record->status, ['draft', 'submitted'], true), 409);
        if (array_diff(array_keys($request->all()), ['file', 'request_revision']) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected protected-leave evidence fields.']);
        }
        $input = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimetypes:application/pdf'],
            'request_revision' => ['required', 'integer', 'min:1'],
        ]);
        abort_unless((int) $record->revision === (int) $input['request_revision'], 409);
        $prior = $this->evidence($company, $leaveRequest);
        abort_if($prior && $prior->status !== 'rejected', 409,
            'Evidence is already awaiting or has completed review.');
        $pdf = $input['file'];
        abort_unless(file_get_contents($pdf->getRealPath(), false, null, 0, 5) === '%PDF-', 422);

        // No original filenames, diagnoses or PII in the object key.
        $path = 'leave-evidence/'.$company.'/'.$leaveRequest.'/'.bin2hex(random_bytes(16)).'.pdf';
        $disk = Storage::disk('minio');
        try {
            $stored = $disk->putFileAs(dirname($path), $pdf, basename($path), ['visibility' => 'private']);
        } catch (\Throwable $exception) {
            report($exception);
            abort(503, 'Private evidence storage is temporarily unavailable.');
        }
        abort_unless($stored === $path, 503, 'Private evidence storage is temporarily unavailable.');

        try {
            $result = DB::transaction(function () use ($request, $company, $leaveRequest, $input,
                $path, $pdf, $audit): array {
                DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
                $current = $this->requestFor($request, $company, $leaveRequest);
                abort_unless(in_array($current->status, ['draft', 'submitted'], true)
                    && (int) $current->revision === (int) $input['request_revision'], 409);
                $old = $this->evidence($company, $leaveRequest);
                abort_if($old && $old->status !== 'rejected', 409);
                $version = (int) ($old->revision ?? 0) + 1;
                $values = [
                    'storage_path' => $path, 'byte_size' => $pdf->getSize(),
                    'sha256' => hash_file('sha256', $pdf->getRealPath()),
                    'status' => 'pending', 'revision' => $version,
                    'uploaded_by_user_id' => $request->user()->getAuthIdentifier(),
                    'reviewed_by_user_id' => null, 'reviewed_at' => null,
                    'review_note' => null, 'updated_at' => now(),
                ];
                if ($old) {
                    $updated = DB::table('leave_request_evidence')->where('id', $old->id)
                        ->where('revision', $old->revision)->where('status', 'rejected')
                        ->update($values);
                    abort_unless($updated === 1, 409);
                } else {
                    DB::table('leave_request_evidence')->insert(array_merge($values, [
                        'id' => (string) Str::uuid(), 'company_id' => $company,
                        'leave_request_id' => $leaveRequest, 'created_at' => now(),
                    ]));
                }
                $audit->record($request->user(), $company, HrAuditAction::ProtectedLeaveEvidenceUploaded,
                    null, ['leave_request_id' => $leaveRequest, 'revision' => $version]);
                return ['old_path' => $old?->storage_path, 'version' => $version];
            }, 3);
        } catch (\Throwable $exception) {
            try { $disk->delete($path); } catch (\Throwable $cleanup) { report($cleanup); }
            throw $exception;
        }
        // Once the new file is durably referenced, retire an old rejected version.
        // A failed cleanup is reported, but never makes the new file publicly accessible.
        if ($result['old_path']) {
            try { $disk->delete($result['old_path']); } catch (\Throwable $cleanup) { report($cleanup); }
        }
        return response()->json(['data' => [
            'leave_request_id' => $leaveRequest, 'status' => 'pending',
            'revision' => $result['version'], 'has_file' => true,
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function review(Request $request, string $company, string $leaveRequest, HrAuditWriter $audit): JsonResponse
    {
        abort_unless(config('hrm.leave_writes_enabled', false)
            && config('hrm.protected_leave_evidence_enabled', false), 404);
        $this->requestFor($request, $company, $leaveRequest, true);
        if (array_diff(array_keys($request->all()), ['revision', 'decision', 'comment']) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected review fields.']);
        }
        $input = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'in:verified,rejected'],
            'comment' => ['nullable', 'string', 'max:500', 'required_if:decision,rejected'],
        ]);
        return DB::transaction(function () use ($request, $company, $leaveRequest, $input, $audit): JsonResponse {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $record = $this->requestFor($request, $company, $leaveRequest, true);
            abort_unless($record->status === 'submitted', 409,
                'Submit the leave request before reviewing evidence.');
            $evidence = $this->evidence($company, $leaveRequest);
            abort_unless($evidence, 404);
            abort_unless((int) $evidence->revision === (int) $input['revision'], 409);
            abort_unless($evidence->status === 'pending', 409, 'Evidence has already been reviewed.');
            // The reviewer must never verify a record linked to their own identity.
            $self = DB::table('employee_user_links')->where('company_id', $company)
                ->where('user_id', $request->user()->getAuthIdentifier())
                ->where('employee_id', $record->employee_id)->exists();
            abort_if($self, 403, 'Cannot review evidence for your own employee record.');
            $changed = DB::table('leave_request_evidence')->where('id', $evidence->id)
                ->where('revision', $evidence->revision)->where('status', 'pending')
                ->update(['status' => $input['decision'],
                    'reviewed_by_user_id' => $request->user()->getAuthIdentifier(),
                    'reviewed_at' => now(), 'review_note' => $input['comment'] ?? null,
                    'revision' => (int) $evidence->revision + 1, 'updated_at' => now()]);
            abort_unless($changed === 1, 409);
            $audit->record($request->user(), $company, HrAuditAction::ProtectedLeaveEvidenceReviewed,
                null, ['leave_request_id' => $leaveRequest, 'decision' => $input['decision']]);
            return response()->json(['data' => [
                'leave_request_id' => $leaveRequest, 'status' => $input['decision'],
                'revision' => (int) $evidence->revision + 1,
                'has_file' => true,
            ]])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function download(Request $request, string $company, string $leaveRequest, HrAuditWriter $audit): StreamedResponse
    {
        $this->requestFor($request, $company, $leaveRequest);
        $record = $this->evidence($company, $leaveRequest);
        abort_unless($record, 404);
        $path = $record->storage_path;
        abort_unless(is_string($path)
            && str_starts_with($path, 'leave-evidence/'.$company.'/'.$leaveRequest.'/')
            && preg_match('/^[a-z0-9\/-]+\.pdf$/', $path) === 1, 404);
        try {
            $disk = Storage::disk('minio');
            abort_unless($disk->exists($path), 404);
            $stream = $disk->readStream($path);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            abort(503, 'Private evidence storage is temporarily unavailable.');
        }
        abort_unless(is_resource($stream), 503);
        try {
            $audit->record($request->user(), $company, HrAuditAction::ProtectedLeaveEvidenceDownloaded,
                null, ['leave_request_id' => $leaveRequest]);
        } catch (\Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
        return response()->streamDownload(static function () use ($stream): void {
            try { fpassthru($stream); } finally { fclose($stream); }
        }, 'supporting-document.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ]);
    }
}
