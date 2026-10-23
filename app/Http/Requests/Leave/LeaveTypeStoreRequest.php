<?php

namespace App\Http\Requests\Leave;

use App\Domain\Worktime\WorkforceAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class LeaveTypeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless((bool) config('hrm.leave_writes_enabled', false), 404);

        return app(WorkforceAuthorization::class)->canManageLeaveTypes(
            $this->user(), (string) $this->route('company'),
        );
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'annual_days' => ['present', 'nullable', 'integer', 'between:1,366'],
            'balance_method' => ['required', Rule::in(['calendar_days', 'working_days'])],
            'category' => ['sometimes', Rule::in(['ordinary', 'maternity', 'childcare', 'medical'])],
        ];
    }

    /** Protected absences must never inherit an ordinary working-day quota. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->input('category', 'ordinary') !== 'ordinary'
                && $this->input('balance_method') !== 'calendar_days') {
                $validator->errors()->add('balance_method',
                    'Protected leave types require calendar-day counting.');
            }
        }];
    }
}
