<?php

namespace App\Http\Requests\Personnel;

use App\Domain\Personnel\EmployeePrivateWriteAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

final class SaveEmployeeEducationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(EmployeePrivateWriteAuthorization::class)->canWrite($this);
    }

    public function rules(): array
    {
        return [
            'education_type' => ['required', 'string', 'max:100'],
            'organization' => ['required', 'string', 'max:255'],
            'started_on' => ['present', 'nullable', 'date_format:Y-m-d'],
            'ended_on' => ['present', 'nullable', 'date_format:Y-m-d'],
            'specialization' => ['present', 'nullable', 'string', 'max:255'],
            'document_reference' => ['present', 'nullable', 'string', 'max:255'],
            'revision' => $this->isMethod('put')
                ? ['required', 'integer', 'between:1,2147483646']
                : ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $start = $this->input('started_on');
            $end = $this->input('ended_on');
            if (is_string($start) && is_string($end)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)
                && $end < $start) {
                $validator->errors()->add('ended_on', 'Completion date cannot precede the start.');
            }
        }];
    }

    protected function passedValidation(): void
    {
        if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
            throw ValidationException::withMessages(['education' => 'Unexpected education fields.']);
        }
    }
}
