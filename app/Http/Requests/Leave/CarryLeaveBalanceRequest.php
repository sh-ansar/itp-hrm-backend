<?php

namespace App\Http\Requests\Leave;

final class CarryLeaveBalanceRequest extends LeaveBalanceWriteRequest
{
    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'source_period_id' => ['required', 'uuid'],
            'days' => ['required', 'integer', 'between:1,366'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    protected function permittedInputKeys(): array
    {
        return ['revision', 'source_period_id', 'days', 'reason'];
    }
}
