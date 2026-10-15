<?php

namespace App\Http\Requests\Personnel;

use App\Domain\Personnel\EmployeePrivateWriteAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class ArchiveEmployeeEducationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(EmployeePrivateWriteAuthorization::class)->canWrite($this);
    }

    public function rules(): array
    {
        return ['revision' => ['required', 'integer', 'between:1,2147483646']];
    }

    protected function passedValidation(): void
    {
        if (array_diff(array_keys($this->all()), ['revision']) !== []) {
            throw ValidationException::withMessages(['education' => 'Unexpected archive fields.']);
        }
    }
}
