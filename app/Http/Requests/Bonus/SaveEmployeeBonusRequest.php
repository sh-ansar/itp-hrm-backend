<?php

namespace App\Http\Requests\Bonus;

use App\Domain\Compensation\BonusAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveEmployeeBonusRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless((bool) config('hrm.bonus_writes_enabled', false), 404);

        return app(BonusAuthorization::class)->canManage(
            $this->user(), (string) $this->route('company'),
        );
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid'],
            'bonus_kind' => ['required', Rule::in(['one_time', 'performance', 'holiday', 'other'])],
            'award_on' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'regex:/^\\d{1,12}(\\.\\d{1,2})?$/', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'basis_reference' => ['required', 'string', 'max:255'],
            'note' => ['present', 'nullable', 'string', 'max:2000'],
            'request_key' => $this->isMethod('post') ? ['required', 'uuid'] : ['prohibited'],
            'revision' => $this->isMethod('put') ? ['required', 'integer', 'between:1,2147483646'] : ['prohibited'],
        ];
    }
}
