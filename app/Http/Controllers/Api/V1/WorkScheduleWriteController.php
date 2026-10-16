<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Worktime\WorkforceAuthorization;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WorkScheduleWriteController extends Controller
{
    private function authorizeWrite(Request $request, string $company, WorkforceAuthorization $authorization): void
    {
        abort_unless((bool) config('hrm.worktime_writes_enabled', false), 404);
        abort_unless($authorization->canEditTimesheet($request->user(), $company), 403);
    }

    public function store(Request $request, string $company, WorkforceAuthorization $authorization, HrAuditWriter $audit): JsonResponse
    {
        $this->authorizeWrite($request, $company, $authorization);
        $data = $this->scheduleData($request);

        $id = DB::transaction(function () use ($request, $company, $data, $audit): string {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            if (DB::table('work_schedules')->where('company_id', $company)->where('code', $data['code'])->exists()) {
                throw ValidationException::withMessages(['code' => 'Schedule code already exists.']);
            }
            $id = (string) Str::uuid();
            DB::table('work_schedules')->insert([
                'id' => $id, 'company_id' => $company, ...$data,
                'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $audit->record($request->user(), $company, HrAuditAction::WorkScheduleCreated, null, [
                'schedule_id' => $id, 'revision' => 1,
            ]);
            return $id;
        }, 3);

        return response()->json(['data' => $this->serializeSchedule($company, $id)], 201);
    }

    public function update(Request $request, string $company, string $schedule, WorkforceAuthorization $authorization, HrAuditWriter $audit): JsonResponse
    {
        $this->authorizeWrite($request, $company, $authorization);
        $data = $this->scheduleData($request, true);
        $expected = (int) $data['revision'];
        unset($data['revision']);

        DB::transaction(function () use ($request, $company, $schedule, $data, $expected, $audit): void {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $current = DB::table('work_schedules')
                ->where('company_id', $company)->where('id', $schedule)->lockForUpdate()->firstOrFail();
            abort_unless((int) $current->revision === $expected, 409, 'Schedule changed since it was loaded.');

            $duplicate = DB::table('work_schedules')
                ->where('company_id', $company)->where('code', $data['code'])
                ->where('id', '<>', $schedule)->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['code' => 'Schedule code already exists.']);
            }

            $count = DB::table('work_schedules')
                ->where('id', $schedule)->where('company_id', $company)->where('revision', $expected)
                ->update([...$data, 'revision' => $expected + 1, 'updated_at' => now()]);
            abort_unless($count === 1, 409, 'Schedule was modified by another user.');

            $audit->record($request->user(), $company, HrAuditAction::WorkScheduleUpdated, null, [
                'schedule_id' => $schedule, 'revision' => $expected + 1,
            ]);
        }, 3);

        return response()->json(['data' => $this->serializeSchedule($company, $schedule)]);
    }

    public function assign(Request $request, string $company, string $schedule, string $employee, WorkforceAuthorization $authorization, HrAuditWriter $audit): JsonResponse
    {
        $this->authorizeWrite($request, $company, $authorization);
        $data = $request->validate(['valid_from' => ['required', 'date_format:Y-m-d']]);
        $validFrom = CarbonImmutable::createFromFormat('!Y-m-d', $data['valid_from']);
        abort_unless($validFrom !== false, 422);

        $assignmentId = DB::transaction(function () use ($request, $company, $schedule, $employee, $validFrom, $audit): string {
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            abort_unless(DB::table('work_schedules')->where('company_id', $company)->where('id', $schedule)->exists(), 404);
            abort_unless(DB::table('employees')->where('company_id', $company)->where('id', $employee)->exists(), 404);

            $current = DB::table('employee_work_schedules')
                ->where('employee_id', $employee)
                ->where('valid_from', '<=', $validFrom->toDateString())
                ->where(function ($query) use ($validFrom): void {
                    $query->whereNull('valid_to')->orWhere('valid_to', '>=', $validFrom->toDateString());
                })
                ->orderByDesc('valid_from')->lockForUpdate()->first();

            if ($current !== null) {
                if ((string) $current->work_schedule_id === $schedule && (string) $current->valid_from === $validFrom->toDateString()) {
                    return (string) $current->id;
                }
                if ((string) $current->valid_from === $validFrom->toDateString()) {
                    DB::table('employee_work_schedules')->where('id', $current->id)->delete();
                } else {
                    DB::table('employee_work_schedules')->where('id', $current->id)->update([
                        'valid_to' => $validFrom->subDay()->toDateString(),
                        'revision' => (int) $current->revision + 1,
                        'updated_at' => now(),
                    ]);
                }
            }

            $future = DB::table('employee_work_schedules')->where('employee_id', $employee)
                ->where('valid_from', '>', $validFrom->toDateString())->orderBy('valid_from')->first(['valid_from']);
            $validTo = $future !== null ? CarbonImmutable::parse($future->valid_from)->subDay()->toDateString() : null;

            $id = (string) Str::uuid();
            DB::table('employee_work_schedules')->insert([
                'id' => $id, 'employee_id' => $employee, 'work_schedule_id' => $schedule,
                'valid_from' => $validFrom->toDateString(), 'valid_to' => $validTo,
                'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $audit->record($request->user(), $company, HrAuditAction::WorkScheduleAssigned, null, [
                'schedule_id' => $schedule, 'employee_id' => $employee, 'valid_from' => $validFrom->toDateString(),
            ]);
            return $id;
        }, 3);

        $row = DB::table('employee_work_schedules as ews')
            ->join('work_schedules as ws', 'ws.id', '=', 'ews.work_schedule_id')
            ->where('ews.id', $assignmentId)
            ->select('ews.id', 'ews.employee_id', 'ews.valid_from', 'ews.valid_to', 'ews.revision',
                'ws.id as schedule_id', 'ws.code as schedule_code', 'ws.name as schedule_name')
            ->firstOrFail();

        return response()->json(['data' => $row]);
    }

    private function scheduleData(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'schedule_type' => ['required', Rule::in(['standard', 'shift', 'individual', 'summarized'])],
            'work_time_label' => ['present', 'nullable', 'string', 'max:128'],
            'weekly_minutes' => ['present', 'nullable', 'integer', 'between:1,10080'],
            'revision' => $updating ? ['required', 'integer', 'between:1,2147483646'] : ['prohibited'],
        ]);
    }

    private function serializeSchedule(string $company, string $schedule): array
    {
        $row = DB::table('work_schedules')->where('company_id', $company)->where('id', $schedule)->firstOrFail();
        return [
            'id' => $row->id, 'code' => $row->code, 'name' => $row->name,
            'schedule_type' => $row->schedule_type, 'work_time_label' => $row->work_time_label,
            'weekly_minutes' => $row->weekly_minutes !== null ? (int) $row->weekly_minutes : null,
            'revision' => (int) $row->revision,
        ];
    }
}
