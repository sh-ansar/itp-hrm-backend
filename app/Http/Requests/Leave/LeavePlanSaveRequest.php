<?php

namespace App\Http\Requests\Leave;

use App\Domain\Worktime\WorkforceAuthorization;
use Illuminate\Foundation\Http\FormRequest;

final class LeavePlanSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless((bool) config('hrm.leave_writes_enabled', false), 404);

        return app(WorkforceAuthorization::class)->canManageLeaveRequests(
            $this->user(), (string) $this->route('company'),
        );
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid'],
            'leave_type_id' => ['required', 'uuid'],
            'planned_started_on' => ['required', 'date_format:Y-m-d'],
            'planned_ended_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:planned_started_on'],
            'note' => ['present', 'nullable', 'string', 'max:2000'],
            'revision' => $this->isMethod('put')
                ? ['required', 'integer', 'between:1,2147483646']
                : ['prohibited'],
        ];
    }
}
