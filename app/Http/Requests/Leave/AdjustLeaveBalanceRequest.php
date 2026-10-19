<?php

namespace App\Http\Requests\Leave;

final class AdjustLeaveBalanceRequest extends LeaveBalanceWriteRequest
{
    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'delta_days' => ['required', 'integer', 'between:-366,366', 'not_in:0'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    protected function permittedInputKeys(): array
    {
        return ['revision', 'delta_days', 'reason'];
    }
}
