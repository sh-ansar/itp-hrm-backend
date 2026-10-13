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

final class EmployeePhotoController extends Controller
{
    private function authorized(Request $request, string $company, string $employee, bool $write): object
    {
        $role = DB::table('company_user_roles')->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())->value('role_code');
        abort_unless(in_array($role, ['company_admin', 'hr_specialist'], true), 404);
        if ($write) {
            abort_unless((bool) config('hrm.personnel_writes_enabled', false), 404);
        }
        $row = DB::table('employees')->where('company_id', $company)->where('id', $employee)
            ->first(['revision', 'photo_path']);
        abort_if($row === null, 404);
        return $row;
    }

    public function upload(Request $request, string $company, string $employee, HrAuditWriter $audit): JsonResponse
    {
        $record = $this->authorized($request, $company, $employee, true);
        if (array_diff(array_keys($request->all()), ['file', 'revision']) !== []) {
            throw ValidationException::withMessages(['file' => 'Unexpected photo fields.']);
        }
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png'],
            'revision' => ['required', 'integer', 'min:1', 'max:2147483646'],
        ]);
        abort_unless((int) $record->revision === (int) $data['revision'], 409);
        $bytes = file_get_contents($data['file']->getRealPath());
        $size = @getimagesizefromstring($bytes);
        if (!$size || !in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
            || max($size[0], $size[1]) > 4096 || $size[0] * $size[1] > 8000000) {
            throw ValidationException::withMessages(['file' => 'Choose a JPEG/PNG up to 4096 pixels and 8 megapixels.']);
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw ValidationException::withMessages(['file' => 'Invalid photo.']);
        }
        // Re-encode decoded pixels: original EXIF, filenames and trailing payloads are discarded.
        if ($size[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $orientation = @exif_read_data($data['file']->getRealPath())['Orientation'] ?? 1;
            if (in_array($orientation, [2, 5, 7], true)) imageflip($image, IMG_FLIP_HORIZONTAL);
            if ($orientation === 4) imageflip($image, IMG_FLIP_VERTICAL);
            $angle = match ($orientation) { 3 => 180, 5, 6 => 270, 7, 8 => 90, default => 0 };
            if ($angle) $image = imagerotate($image, $angle, 0);
            $size[0] = imagesx($image);
            $size[1] = imagesy($image);
        }
        $scale = min(1, 1024 / max($size[0], $size[1]));
        $width = max(1, (int) round($size[0] * $scale));
        $height = max(1, (int) round($size[1] * $scale));
        $normalized = imagecreatetruecolor($width, $height);
        imagefill($normalized, 0, 0, imagecolorallocate($normalized, 255, 255, 255));
        imagecopyresampled($normalized, $image, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);
        ob_start();
        imagejpeg($normalized, null, 90);
        $jpeg = ob_get_clean();
        $path = 'employee-photos/'.$company.'/'.$employee.'/'.bin2hex(random_bytes(16)).'.jpg';
        abort_unless(Storage::disk('local')->put($path, $jpeg), 500);
        try {
            $this->commitPhoto($request, $company, $employee, $record, $path, $audit);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        if (is_string($record->photo_path)) Storage::disk('local')->delete($record->photo_path);
        return response()->json(['data' => ['has_photo' => true, 'revision' => (int) $record->revision + 1]]);
    }

    public function destroy(Request $request, string $company, string $employee, HrAuditWriter $audit): JsonResponse
    {
        $record = $this->authorized($request, $company, $employee, true);
        if (array_diff(array_keys($request->all()), ['revision']) !== []) {
            throw ValidationException::withMessages(['photo' => 'Unexpected photo fields.']);
        }
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1', 'max:2147483646']]);
        abort_unless((int) $record->revision === (int) $data['revision'], 409);
        abort_unless(is_string($record->photo_path), 404);
        $this->commitPhoto($request, $company, $employee, $record, null, $audit);
        Storage::disk('local')->delete($record->photo_path);
        return response()->json(['data' => ['has_photo' => false, 'revision' => (int) $record->revision + 1]]);
    }

    private function commitPhoto(Request $request, string $company, string $employee, object $record, ?string $path, HrAuditWriter $audit): void
    {
        DB::transaction(function () use ($request, $company, $employee, $record, $path, $audit): void {
            $updated = DB::table('employees')->where('company_id', $company)->where('id', $employee)
                ->where('revision', $record->revision)->update([
                    'photo_path' => $path, 'revision' => (int) $record->revision + 1, 'updated_at' => now(),
                ]);
            abort_unless($updated === 1, 409);
            $audit->record($request->user(), $company,
                $path === null ? HrAuditAction::EmployeePhotoRemoved : HrAuditAction::EmployeePhotoUpdated, null,
                ['employee_id' => $employee, 'revision' => (int) $record->revision + 1]);
        });
    }

    public function show(Request $request, string $company, string $employee): BinaryFileResponse
    {
        $record = $this->authorized($request, $company, $employee, false);
        abort_unless(is_string($record->photo_path) && Storage::disk('local')->exists($record->photo_path), 404);
        return response()->file(Storage::disk('local')->path($record->photo_path), [
            'Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
