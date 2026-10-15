<?php

namespace App\Http\Requests\Bonus;

use App\Domain\Compensation\BonusAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TransitionEmployeeBonusRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless((bool) config('hrm.bonus_writes_enabled', false), 404);

        $authorization = app(BonusAuthorization::class);
        $company = (string) $this->route('company');

        return str_ends_with($this->path(), '/decision')
            ? $authorization->canApprove($this->user(), $company)
            : $authorization->canManage($this->user(), $company);
    }

    public function rules(): array
    {
        $isDecision = str_ends_with($this->path(), '/decision');

        return [
            'revision' => ['required', 'integer', 'between:1,2147483646'],
            'decision' => $isDecision ? ['required', Rule::in(['approved', 'rejected'])] : ['prohibited'],
            'comment' => $isDecision
                ? ['nullable', 'string', 'max:2000', 'required_if:decision,rejected']
                : ['prohibited'],
        ];
    }
}
