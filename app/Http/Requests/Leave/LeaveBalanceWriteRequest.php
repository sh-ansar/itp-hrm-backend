<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/** Single authorization boundary for financially sensitive leave grants/rollovers. */
abstract class LeaveBalanceWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(config('hrm.leave_writes_enabled', false)
            && config('hrm.leave_balance_writes_enabled', false), 404);
        return DB::table('company_user_roles')
            ->where('company_id', (string) $this->route('company'))
            ->where('user_id', $this->user()?->getAuthIdentifier())
            ->where('role_code', 'company_admin')
            ->exists();
    }

    abstract protected function permittedInputKeys(): array;

    public function after(): array
    {
        return [function (Validator $validator): void {
            $unexpected = array_diff(array_keys($this->all()), $this->permittedInputKeys());
            if ($unexpected !== []) $validator->errors()->add('request', 'Unexpected leave balance fields.');
        }];
    }
}
