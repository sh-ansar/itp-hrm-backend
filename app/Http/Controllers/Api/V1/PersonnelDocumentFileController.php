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
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PersonnelDocumentFileController extends Controller
{
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
        $filename = 'document.pdf';
        $path = 'personnel-documents/'.$company.'/'.$document.'/'.bin2hex(random_bytes(16)).'.pdf';
        $stored = Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        if ($stored === false) {
            abort(500, 'Unable to store document');
        }

        try {
            DB::transaction(function () use ($request, $audit, $company, $document, $record, $path, $file, $filename): void {
                $updated = DB::table('personnel_documents')
                    ->where('id', $document)->where('company_id', $company)
                    ->where('revision', $record->revision)->whereNull('storage_path')
                    ->update([
                        'storage_disk' => 'local',
                        'storage_path' => $path,
                        'original_filename' => $filename,
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
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return response()->json(['data' => [
            'id' => $document, 'has_file' => true, 'revision' => (int) $record->revision + 1,
        ]]);
    }

    public function download(Request $request, string $company, string $document, HrAuditWriter $audit): BinaryFileResponse
    {
        $record = $this->authorized($request, $company, $document, false);
        abort_if($record->storage_disk !== 'local' || !is_string($record->storage_path), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($record->storage_path), 404);

        $audit->record($request->user(), $company, HrAuditAction::PersonnelDocumentFileDownloaded, null, [
            'document_id' => $document,
        ]);

        return response()->download($disk->path($record->storage_path), 'document.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
