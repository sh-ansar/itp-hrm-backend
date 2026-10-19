<?php

namespace App\Http\Requests\Leave;

use Illuminate\Validation\Rule;

final class OpenLeaveBalanceRequest extends LeaveBalanceWriteRequest
{
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid'],
            'leave_type_id' => ['required', 'uuid'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'granted_days' => ['required', 'integer', 'between:0,366'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    protected function permittedInputKeys(): array
    {
        return ['employee_id', 'leave_type_id', 'year', 'granted_days', 'reason'];
    }
}
