<?php

namespace App\Http\Requests\Leave;

use App\Domain\Worktime\WorkforceAuthorization;
use Illuminate\Foundation\Http\FormRequest;

final class ReverseApprovedLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(config('hrm.approved_leave_reversal_enabled', false)
            && config('hrm.leave_writes_enabled', false), 404);
        return app(WorkforceAuthorization::class)->canReverseApprovedLeave(
            $this->user(), (string) $this->route('company'),
        );
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) $this->merge(['reason' => trim($this->input('reason'))]);
    }

    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'between:1,2147483646'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
