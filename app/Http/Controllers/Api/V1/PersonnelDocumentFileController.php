<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PersonnelDocumentFileController extends Controller
{
    /**
     * Authorization is performed before any object-store call or metadata disclosure.
     * No document can be fetched using just an object key, company UUID or signed URL.
     */
    private function authorized(Request $request, string $company, string $document, bool $write): object
    {
        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->value('role_code');
        abort_unless(in_array($role, ['company_admin', 'hr_specialist'], true), 404);

        if ($write) {
            abort_unless((bool) config('hrm.document_writes_enabled', false), 404);
        }

        $row = DB::table('personnel_documents as d')
            ->join('employees as e', 'e.id', '=', 'd.employee_id')
            ->where('d.id', $document)->where('d.company_id', $company)
            ->where('e.company_id', $company)
            ->select('d.*')->first();
        abort_if($row === null, 404);

        return $row;
    }

    public function upload(Request $request, string $company, string $document, HrAuditWriter $audit): JsonResponse
    {
        $record = $this->authorized($request, $company, $document, true);
        abort_unless($record->status === 'draft' && $record->storage_path === null, 409);
        if (array_diff(array_keys($request->all()), ['file', 'revision']) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected attachment fields.']);
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimetypes:application/pdf'],
            'revision' => ['required', 'integer', 'min:1'],
        ]);
        abort_unless((int) $record->revision === (int) $data['revision'], 409);

        $file = $data['file'];
        if (file_get_contents($file->getRealPath(), false, null, 0, 5) !== '%PDF-') {
            throw ValidationException::withMessages(['file' => 'The attachment must contain a valid PDF header.']);
        }

        // Only opaque server-generated keys. No original filenames or externally supplied paths.
        $path = 'personnel-documents/'.$company.'/'.$document.'/'.bin2hex(random_bytes(16)).'.pdf';
        $disk = Storage::disk('minio');
        try {
            $stored = $disk->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private']);
        } catch (\Throwable $exception) {
            report($exception);
            abort(503, 'Document storage is temporarily unavailable.');
        }
        abort_unless($stored === $path, 503, 'Document storage is temporarily unavailable.');

        try {
            DB::transaction(function () use ($request, $audit, $company, $document, $record, $path, $file): void {
                $updated = DB::table('personnel_documents')
                    ->where('id', $document)->where('company_id', $company)
                    ->where('revision', $record->revision)->whereNull('storage_path')
                    ->update([
                        'storage_disk' => 'minio',
                        'storage_path' => $path,
                        'original_filename' => 'document.pdf',
                        'content_type' => 'application/pdf',
                        'byte_size' => $file->getSize(),
                        'sha256' => hash_file('sha256', $file->getRealPath()),
                        'revision' => (int) $record->revision + 1,
                        'updated_at' => now(),
                    ]);
                abort_unless($updated === 1, 409);
                $audit->record($request->user(), $company, HrAuditAction::PersonnelDocumentFileAttached, null, [
                    'document_id' => $document,
                ]);
            }, 3);
        } catch (\Throwable $exception) {
            try {
                $disk->delete($path);
            } catch (\Throwable $cleanupException) {
                report($cleanupException);
            }
            throw $exception;
        }

        return response()->json(['data' => [
            'id' => $document, 'has_file' => true, 'revision' => (int) $record->revision + 1,
        ]]);
    }

    public function download(Request $request, string $company, string $document, HrAuditWriter $audit): StreamedResponse
    {
        $record = $this->authorized($request, $company, $document, false);
        return $this->streamAuthorizedPdf($request, $company, $document, $record, $audit,
            HrAuditAction::PersonnelDocumentFileDownloaded);
    }

    /** Own-document access cannot rely on the broader HR document download route. */
    public function downloadForEmployee(Request $request, string $company, string $document, HrAuditWriter $audit): StreamedResponse
    {
        $userId = $request->user()->getAuthIdentifier();
        $member = DB::table('company_user_roles')
            ->where('company_id', $company)->where('user_id', $userId)
            ->whereIn('role_code', ['company_admin', 'hr_specialist', 'department_manager', 'employee'])
            ->exists();
        abort_unless($member, 404);

        $ownEmployee = DB::table('employee_user_links')
            ->where('company_id', $company)->where('user_id', $userId)->value('employee_id');
        abort_unless($ownEmployee, 404);
        $record = DB::table('personnel_documents as d')
            ->join('employees as e', 'e.id', '=', 'd.employee_id')
            ->where('d.id', $document)->where('d.company_id', $company)
            ->where('d.employee_id', $ownEmployee)->where('e.company_id', $company)
            ->where('d.employee_visible', true)
            ->where('d.storage_disk', 'minio')->whereNotNull('d.storage_path')
            ->whereIn('d.document_type', ['order', 'contract'])
            ->select('d.*')->first();
        abort_unless($record, 404);

        return $this->streamAuthorizedPdf($request, $company, $document, $record, $audit,
            HrAuditAction::EmployeeDocumentDownloaded);
    }

    /** Private authenticated, audited byte stream only. Never return object-store URLs. */
    private function streamAuthorizedPdf(Request $request, string $company, string $document, object $record,
        HrAuditWriter $audit, HrAuditAction $action): StreamedResponse
    {
        $diskName = $record->storage_disk;
        $isMinio = $diskName === 'minio';
        $isLegacy = $action === HrAuditAction::PersonnelDocumentFileDownloaded
            && $diskName === 'local' && (bool) config('hrm.legacy_document_reads_enabled', false);
        abort_unless($isMinio || $isLegacy, 404);

        $path = $record->storage_path;
        abort_unless(is_string($path)
            && str_starts_with($path, 'personnel-documents/'.$company.'/'.$document.'/')
            && preg_match('/^[a-z0-9\/-]+\.pdf$/', $path) === 1, 404);

        $disk = Storage::disk($diskName);
        try {
            abort_unless($disk->exists($path), 404);
            $stream = $disk->readStream($path);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            abort(503, 'Document storage is temporarily unavailable.');
        }
        abort_unless(is_resource($stream), 503, 'Document storage is temporarily unavailable.');

        try {
            $audit->record($request->user(), $company, $action, null, ['document_id' => $document]);
        } catch (\Throwable $exception) {
            fclose($stream);
            throw $exception;
        }

        return response()->streamDownload(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 'document.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
        ]);
    }
}
