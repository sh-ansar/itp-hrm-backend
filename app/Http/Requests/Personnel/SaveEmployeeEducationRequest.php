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
            // Both years are optional for v1 clients that still send full dates.
            'started_year' => ['sometimes', 'nullable', 'integer', 'between:1850,2200'],
            'ended_year' => ['sometimes', 'nullable', 'integer', 'between:1850,2200'],
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
            $yearStart = $this->input('started_year');
            $yearEnd = $this->input('ended_year');
            if (is_numeric($yearStart) && is_numeric($yearEnd) && (int) $yearEnd < (int) $yearStart) {
                $validator->errors()->add('ended_year', 'Completion year cannot precede start year.');
            }
            if (is_numeric($yearStart) && is_string($start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)
                && (int) substr($start, 0, 4) !== (int) $yearStart) {
                $validator->errors()->add('started_year', 'Start year conflicts with precise start date.');
            }
            if (is_numeric($yearEnd) && is_string($end) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)
                && (int) substr($end, 0, 4) !== (int) $yearEnd) {
                $validator->errors()->add('ended_year', 'End year conflicts with precise completion date.');
            }
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
