<?php

namespace App\Domain\Personnel;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Storage / read model for prototype "Personal details" and "Military records".
 * Never return private fields through registry queries or employee-role reads.
 */
final class EmployeePrivateProfile
{
    public const PERSONAL_FIELDS = [
        'login', 'iin', 'nationality', 'citizenship', 'marital_status',
        'residence_address', 'registration_address', 'address_entrance',
        'address_floor', 'address_apartment', 'place_of_birth',
        'emergency_contact_name', 'emergency_contact_phone',
    ];
    public const MILITARY_FIELDS = ['status', 'category', 'document_reference', 'comment'];

    public function __construct(private readonly HrAuditWriter $audit) {}

    public function read(string $companyId, string $employeeId): array
    {
        // The controller has already authorized the employee company and HR role.
        $personal = DB::table('employee_private_profiles')
            ->where('company_id', $companyId)->where('employee_id', $employeeId)->first();
        $military = DB::table('employee_military_registration')
            ->where('company_id', $companyId)->where('employee_id', $employeeId)->first();

        return [
            'personal' => $this->personalData($personal),
            'military' => $this->militaryData($military),
        ];
    }

    public function save(User $actor, string $companyId, string $employeeId, string $section, array $input): array
    {
        return DB::transaction(function () use ($actor, $companyId, $employeeId, $section, $input): array {
            // A single parent-row lock serializes first-time creation and
            // subsequent updates across two concurrent browser sessions.
            DB::table('employees')->where('id', $employeeId)
                ->where('company_id', $companyId)->lockForUpdate()->firstOrFail();

            $personal = $section === 'personal';
            $table = $personal ? 'employee_private_profiles' : 'employee_military_registration';
            $expected = $input['revision'];
            $current = DB::table($table)->where('employee_id', $employeeId)
                ->where('company_id', $companyId)->lockForUpdate()->first();

            abort_if(($current === null && $expected !== null)
                || ($current !== null && ($expected === null || (int) $current->revision !== $expected)),
                409, 'Private profile was changed. Refresh before saving.');

            $fields = $personal ? self::PERSONAL_FIELDS : self::MILITARY_FIELDS;
            $update = [];
            foreach ($fields as $field) {
                if ($personal && $field === 'iin') {
                    // Encrypt national identifiers with Laravel's server-side
                    // APP_KEY, never store plaintext or put it into audit logs.
                    $update['iin_ciphertext'] = $input['iin'] === null
                        ? null : Crypt::encryptString($input['iin']);
                    continue;
                }
                $column = $field === 'login' ? 'login_name' : $field;
                $update[$column] = $input[$field];
            }
            $next = $current === null ? 1 : (int) $current->revision + 1;
            if ($current === null) {
                DB::table($table)->insert([
                    'employee_id' => $employeeId,
                    'company_id' => $companyId,
                    ...$update,
                    'revision' => $next,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $count = DB::table($table)->where('employee_id', $employeeId)
                    ->where('company_id', $companyId)->where('revision', $expected)
                    ->update([...$update, 'revision' => $next, 'updated_at' => now()]);
                abort_unless($count === 1, 409, 'Private profile was changed. Refresh before saving.');
            }

            $this->audit->record($actor, $companyId, HrAuditAction::EmployeePrivateProfileUpdated, null, [
                'employee_id' => $employeeId,
                'section' => $section,
                'revision' => $next,
                // No field values, IIN, names, addresses or comments in audit.
            ]);

            return ['section' => $section, 'revision' => $next];
        }, 3);
    }

    private function personalData(?object $record): array
    {
        $data = ['revision' => $record === null ? null : (int) $record->revision];
        foreach (self::PERSONAL_FIELDS as $field) {
            if ($field === 'iin') {
                $data['iin'] = $record?->iin_ciphertext === null
                    ? null : Crypt::decryptString($record->iin_ciphertext);
            } else {
                $data[$field] = $record?->{$field === 'login' ? 'login_name' : $field};
            }
        }
        return $data;
    }

    private function militaryData(?object $record): array
    {
        $data = ['revision' => $record === null ? null : (int) $record->revision];
        foreach (self::MILITARY_FIELDS as $field) {
            $data[$field] = $record?->{$field};
        }
        return $data;
    }
}
