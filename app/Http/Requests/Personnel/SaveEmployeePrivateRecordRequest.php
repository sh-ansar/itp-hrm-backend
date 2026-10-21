<?php

namespace App\Http\Requests\Personnel;

use App\Domain\Personnel\EmployeePrivateWriteAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class SaveEmployeePrivateRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(in_array($this->route('section'), ['family', 'identity', 'address'], true), 404);
        return app(EmployeePrivateWriteAuthorization::class)->canWrite($this);
    }

    public function rules(): array
    {
        $fields = match ($this->route('section')) {
            'family' => [
                'relationship' => ['required', 'string', 'max:100'],
                'full_name' => ['required', 'string', 'max:255'],
                'birth_date' => ['present', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
                'document_reference' => ['present', 'nullable', 'string', 'max:255'],
            ],
            'address' => [
                'kind' => ['required', 'string', \Illuminate\Validation\Rule::in(['residence','registration','other'])],
                'address_line' => ['required', 'string', 'max:500'],
                'entrance' => ['present', 'nullable', 'string', 'max:40'],
                'floor' => ['present', 'nullable', 'string', 'max:20'],
                'apartment' => ['present', 'nullable', 'string', 'max:40'],
                'is_primary' => ['required', 'boolean'],
            ],
            default => [
                'type' => ['required', 'string', 'max:100'],
                'number' => ['required', 'string', 'min:2', 'max:100'],
                'personal_number' => ['present', 'nullable', 'string', 'max:100'],
                'country_of_issue' => ['present', 'nullable', 'string', 'max:100'],
                'issued_by' => ['present', 'nullable', 'string', 'max:255'],
                'issued_on' => ['present', 'nullable', 'date_format:Y-m-d'],
                'expires_on' => ['present', 'nullable', 'date_format:Y-m-d', 'after_or_equal:issued_on'],
                'is_primary' => ['required', 'boolean'],
            ],
        };

        $fields['revision'] = $this->isMethod('put')
            ? ['required', 'integer', 'between:1,2147483646']
            : ['prohibited'];

        return $fields;
    }

    protected function passedValidation(): void
    {
        if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
            throw ValidationException::withMessages(['record' => 'Unexpected private record fields.']);
        }
    }
}
