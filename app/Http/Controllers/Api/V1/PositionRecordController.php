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

final class PositionRecordController extends Controller
{
    public function store(Request $request, string $company, HrAuditWriter $audit): JsonResponse
    {
        $this->authorizeWrite($request, $company);
        $this->rejectUnexpected($request, ['name']);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $id = (string) Str::uuid();

        DB::transaction(function () use ($request, $company, $data, $id, $audit): void {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();

            DB::table('positions')->insert([
                'id' => $id,
                'company_id' => $company,
                'name' => $data['name'],
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $audit->record($request->user(), $company, HrAuditAction::PositionCreated, null, [
                'position_id' => $id,
                'revision' => 1,
            ]);
        });

        return response()->json(['data' => [
            'id' => $id,
            'name' => $data['name'],
            'revision' => 1,
        ]], 201);
    }

    public function update(
        Request $request,
        string $company,
        string $position,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeWrite($request, $company);
        $this->rejectUnexpected($request, ['name', 'revision']);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'revision' => ['required', 'integer', 'min:1'],
        ]);
        $expectedRevision = (int) $data['revision'];

        DB::transaction(function () use ($request, $company, $position, $data, $expectedRevision, $audit): void {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();

            $current = DB::table('positions')
                ->where('id', $position)
                ->where('company_id', $company)
                ->lockForUpdate()
                ->first(['revision']);
            abort_if($current === null, 404);

            if ((int) $current->revision !== $expectedRevision) {
                abort(409, 'The position was changed by another user. Reload before editing.');
            }

            $updated = DB::table('positions')
                ->where('id', $position)
                ->where('company_id', $company)
                ->where('revision', $expectedRevision)
                ->update([
                    'name' => $data['name'],
                    'revision' => $expectedRevision + 1,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                abort(409, 'The position was changed by another user. Reload before editing.');
            }

            $audit->record($request->user(), $company, HrAuditAction::PositionUpdated, null, [
                'position_id' => $position,
                'revision' => $expectedRevision + 1,
            ]);
        });

        return response()->json(['data' => [
            'id' => $position,
            'name' => $data['name'],
            'revision' => $expectedRevision + 1,
        ]]);
    }

    public function destroy(
        Request $request,
        string $company,
        string $position,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeWrite($request, $company);
        $this->rejectUnexpected($request, ['revision']);

        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
        ]);
        $expectedRevision = (int) $data['revision'];

        DB::transaction(function () use ($request, $company, $position, $expectedRevision, $audit): void {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();

            $current = DB::table('positions')
                ->where('id', $position)
                ->where('company_id', $company)
                ->lockForUpdate()
                ->first(['revision']);
            abort_if($current === null, 404);

            if ((int) $current->revision !== $expectedRevision) {
                abort(409, 'The position was changed by another user. Reload before deleting.');
            }

            if (DB::table('employee_assignments')->where('position_id', $position)->exists()) {
                throw ValidationException::withMessages(['position' => 'Position is used in employee assignment history.']);
            }
            if (DB::table('staffing_plan_lines')->where('position_id', $position)->exists()) {
                throw ValidationException::withMessages(['position' => 'Position is used in staffing plans.']);
            }

            $deleted = DB::table('positions')
                ->where('id', $position)
                ->where('company_id', $company)
                ->where('revision', $expectedRevision)
                ->delete();

            if ($deleted !== 1) {
                abort(409, 'The position was changed by another user. Reload before deleting.');
            }

            $audit->record($request->user(), $company, HrAuditAction::PositionDeleted, null, [
                'position_id' => $position,
                'revision' => $expectedRevision,
            ]);
        });

        return response()->json(['data' => ['id' => $position]]);
    }

    private function rejectUnexpected(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages(['request' => 'Unexpected position fields.']);
        }
    }

    private function authorizeWrite(Request $request, string $company): void
    {
        abort_unless((bool) config('hrm.personnel_writes_enabled', false), 404);

        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->value('role_code');

        abort_unless(in_array($role, ['company_admin', 'hr_specialist'], true), 403);
    }
}
