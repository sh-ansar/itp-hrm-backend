<?php

namespace App\Domain\Personnel;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Versioned, repeatable employee-private records. Documents are only metadata;
 * upload and download remain the responsibility of the private file subsystem.
 */
final class EmployeePrivateRecords
{
    private const TABLES = [
        'family' => 'employee_family_members',
        'identity' => 'employee_identity_documents',
    ];

    public function __construct(private readonly HrAuditWriter $audit) {}

    public function read(string $company, string $employee): array
    {
        $family = DB::table('employee_family_members')
            ->where('company_id', $company)->where('employee_id', $employee)
            ->whereNull('archived_at')->orderBy('created_at')->orderBy('id')
            ->limit(101)->get();
        $identities = DB::table('employee_identity_documents')
            ->where('company_id', $company)->where('employee_id', $employee)
            ->whereNull('archived_at')->orderByDesc('is_primary')
            ->orderByDesc('created_at')->orderBy('id')
            ->limit(101)->get();

        return [
            'family' => $family->take(100)->map(static fn (object $row): array => [
                'id' => $row->id,
                'relationship' => $row->relationship,
                'full_name' => $row->full_name,
                'birth_date' => $row->birth_date,
                'document_reference' => $row->document_reference,
                'revision' => (int) $row->revision,
            ])->values()->all(),
            'identity_documents' => $identities->take(100)
                ->map(fn (object $row): array => $this->identityView($row))
                ->values()->all(),
            'family_truncated' => $family->count() > 100,
            'identity_documents_truncated' => $identities->count() > 100,
        ];
    }

    public function create(User $actor, string $company, string $employee, string $section, array $input): array
    {
        return DB::transaction(function () use ($actor, $company, $employee, $section, $input): array {
            $this->lockEmployee($company, $employee);
            $id = (string) Str::uuid();
            if ($section === 'identity' && $input['is_primary']) $this->clearPrimary($company, $employee);
            DB::table(self::TABLES[$section])->insert([
                'id' => $id, 'company_id' => $company, 'employee_id' => $employee,
                ...$this->fields($section, $input),
                'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record($actor, $company, HrAuditAction::EmployeePrivateRecordCreated, null, [
                'employee_id' => $employee, 'section' => $section, 'record_id' => $id, 'revision' => 1,
            ]);
            return ['id' => $id, 'revision' => 1];
        }, 3);
    }

    public function update(
        User $actor, string $company, string $employee, string $section,
        string $record, array $input,
    ): array {
        return DB::transaction(function () use ($actor, $company, $employee, $section, $record, $input): array {
            $this->lockEmployee($company, $employee);
            $existing = $this->findActive($company, $employee, $section, $record);
            $expected = (int) $input['revision'];
            $this->ensureRevision($existing, $expected);
            if ($section === 'identity' && $input['is_primary']) {
                $this->clearPrimary($company, $employee, $record);
            }
            $count = DB::table(self::TABLES[$section])->where('company_id', $company)
                ->where('employee_id', $employee)->where('id', $record)
                ->whereNull('archived_at')->where('revision', $expected)->update([
                    ...$this->fields($section, $input),
                    'revision' => $expected + 1, 'updated_at' => now(),
                ]);
            abort_unless($count === 1, 409, 'Private record changed; reload it.');
            $this->audit->record($actor, $company, HrAuditAction::EmployeePrivateRecordUpdated, null, [
                'employee_id' => $employee, 'section' => $section,
                'record_id' => $record, 'revision' => $expected + 1,
            ]);
            return ['id' => $record, 'revision' => $expected + 1];
        }, 3);
    }

    public function archive(
        User $actor, string $company, string $employee, string $section,
        string $record, int $expected,
    ): array {
        return DB::transaction(function () use ($actor, $company, $employee, $section, $record, $expected): array {
            $this->lockEmployee($company, $employee);
            $existing = $this->findActive($company, $employee, $section, $record);
            $this->ensureRevision($existing, $expected);
            $count = DB::table(self::TABLES[$section])->where('company_id', $company)
                ->where('employee_id', $employee)->where('id', $record)
                ->whereNull('archived_at')->where('revision', $expected)->update([
                    'archived_at' => now(), 'revision' => $expected + 1, 'updated_at' => now(),
                ]);
            abort_unless($count === 1, 409, 'Private record changed; reload it.');
            $this->audit->record($actor, $company, HrAuditAction::EmployeePrivateRecordArchived, null, [
                'employee_id' => $employee, 'section' => $section,
                'record_id' => $record, 'revision' => $expected + 1,
            ]);
            return ['id' => $record, 'revision' => $expected + 1, 'archived' => true];
        }, 3);
    }

    private function identityView(object $row): array
    {
        return [
            'id' => $row->id,
            'type' => $row->type,
            'number' => Crypt::decryptString($row->number_ciphertext),
            'personal_number' => $row->personal_number_ciphertext === null
                ? null : Crypt::decryptString($row->personal_number_ciphertext),
            'country_of_issue' => $row->country_of_issue,
            'issued_by' => $row->issued_by,
            'issued_on' => $row->issued_on,
            'expires_on' => $row->expires_on,
            'is_primary' => (bool) $row->is_primary,
            'revision' => (int) $row->revision,
        ];
    }

    private function fields(string $section, array $input): array
    {
        if ($section === 'family') {
            return [
                'relationship' => $input['relationship'],
                'full_name' => $input['full_name'],
                'birth_date' => $input['birth_date'],
                'document_reference' => $input['document_reference'],
            ];
        }
        return [
            'type' => $input['type'],
            'number_ciphertext' => Crypt::encryptString($input['number']),
            'personal_number_ciphertext' => $input['personal_number'] === null
                ? null : Crypt::encryptString($input['personal_number']),
            'country_of_issue' => $input['country_of_issue'],
            'issued_by' => $input['issued_by'],
            'issued_on' => $input['issued_on'],
            'expires_on' => $input['expires_on'],
            'is_primary' => $input['is_primary'],
        ];
    }

    private function clearPrimary(string $company, string $employee, ?string $excluding = null): void
    {
        $query = DB::table('employee_identity_documents')
            ->where('company_id', $company)->where('employee_id', $employee)
            ->whereNull('archived_at')->where('is_primary', true);
        if ($excluding !== null) $query->where('id', '<>', $excluding);
        // Implicit demotion also advances revision; stale clients cannot
        // silently override primary-selection changes.
        $query->update(['is_primary' => false, 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
    }

    private function lockEmployee(string $company, string $employee): void
    {
        DB::table('employees')->where('company_id', $company)->where('id', $employee)
            ->lockForUpdate()->firstOrFail();
    }

    private function findActive(string $company, string $employee, string $section, string $record): object
    {
        return DB::table(self::TABLES[$section])->where('company_id', $company)
            ->where('employee_id', $employee)->where('id', $record)
            ->whereNull('archived_at')->lockForUpdate()->firstOrFail();
    }

    private function ensureRevision(object $row, int $expected): void
    {
        abort_unless((int) $row->revision === $expected, 409, 'Private record changed; reload before saving.');
    }
}
