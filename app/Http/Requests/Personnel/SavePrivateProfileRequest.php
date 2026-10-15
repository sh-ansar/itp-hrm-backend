<?php

namespace App\Http\Requests\Personnel;

use App\Domain\Personnel\EmployeeVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SavePrivateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless((bool) config('hrm.personnel_writes_enabled', false), 404);
        abort_unless(in_array($this->route('section'), ['personal', 'military'], true), 404);

        $company = (string) $this->route('company');
        $employee = (string) $this->route('employee');
        $role = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->where('user_id', $this->user()->getAuthIdentifier())
            ->value('role_code');
        if (! in_array($role, ['company_admin', 'hr_specialist'], true)) {
            return false;
        }

        app(EmployeeVisibility::class)->visibleFor($this->user(), $company)
            ->where('employees.id', $employee)->firstOrFail();

        return true;
    }

    public function rules(): array
    {
        $fields = $this->route('section') === 'personal'
            ? [
                'login' => 100,
                'iin' => 32,
                'nationality' => 100,
                'citizenship' => 100,
                'marital_status' => 80,
                'residence_address' => 500,
                'registration_address' => 500,
                'address_entrance' => 40,
                'address_floor' => 20,
                'address_apartment' => 40,
                'place_of_birth' => 500,
                'emergency_contact_name' => 255,
                'emergency_contact_phone' => 40,
            ]
            : [
                'status' => 100,
                'category' => 100,
                'document_reference' => 255,
                'comment' => 2000,
            ];

        $rules = [
            'revision' => ['present', 'nullable', 'integer', 'between:1,2147483646'],
        ];
        foreach ($fields as $field => $maxLength) {
            // PUT replaces an entire section. Explicit null prevents accidental
            // erasure from a client sending an incomplete object.
            $rules[$field] = ['present', 'nullable', 'string', 'max:'.$maxLength];
        }
        return $rules;
    }

    protected function passedValidation(): void
    {
        $allowed = array_keys($this->rules());
        if (array_diff(array_keys($this->all()), $allowed) !== []) {
            throw ValidationException::withMessages([
                'profile' => 'Unexpected private profile fields.',
            ]);
        }
    }
}
