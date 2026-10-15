<?php

namespace App\Domain\Personnel;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Formal education is historical and separate from employee_training.
 * This service owns revisions, non-destructive archive and safe audit.
 */
final class EmployeeEducationRecords
{
    public function __construct(private readonly HrAuditWriter $audit) {}

    public function read(string $companyId, string $employeeId): array
    {
        $rows = DB::table('employee_education_records')
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->whereNull('archived_at')
            ->select('id', 'education_type', 'organization', 'specialization',
                'started_on', 'ended_on', 'document_reference', 'revision')
            ->orderByDesc('ended_on')
            ->orderByDesc('started_on')
            ->orderBy('id')
            ->limit(101)
            ->get();

        return [
            'education' => $rows->take(100)->map(static fn (object $row): array => [
                'id' => $row->id,
                'education_type' => $row->education_type,
                'organization' => $row->organization,
                'specialization' => $row->specialization,
                'started_on' => $row->started_on,
                'ended_on' => $row->ended_on,
                'document_reference' => $row->document_reference,
                'revision' => (int) $row->revision,
            ])->values()->all(),
            'education_truncated' => $rows->count() > 100,
        ];
    }

    public function create(User $actor, string $company, string $employee, array $data): array
    {
        return DB::transaction(function () use ($actor, $company, $employee, $data): array {
            $this->lockEmployee($company, $employee);
            $id = (string) Str::uuid();
            DB::table('employee_education_records')->insert([
                'id' => $id, 'company_id' => $company, 'employee_id' => $employee,
                ...$this->fields($data), 'revision' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record($actor, $company, HrAuditAction::EmployeeEducationCreated, null, [
                'employee_id' => $employee, 'education_record_id' => $id, 'revision' => 1,
            ]);
            return ['id' => $id, 'revision' => 1];
        }, 3);
    }

    public function update(
        User $actor, string $company, string $employee, string $record, array $data,
    ): array {
        return DB::transaction(function () use ($actor, $company, $employee, $record, $data): array {
            $this->lockEmployee($company, $employee);
            $existing = $this->activeRecord($company, $employee, $record);
            $expected = (int) $data['revision'];
            $this->assertRevision($existing, $expected);
            $updated = DB::table('employee_education_records')
                ->where('company_id', $company)->where('employee_id', $employee)
                ->where('id', $record)->whereNull('archived_at')->where('revision', $expected)
                ->update([
                    ...$this->fields($data),
                    'revision' => $expected + 1, 'updated_at' => now(),
                ]);
            abort_unless($updated === 1, 409, 'Education record changed; reload before saving.');
            $this->audit->record($actor, $company, HrAuditAction::EmployeeEducationUpdated, null, [
                'employee_id' => $employee, 'education_record_id' => $record,
                'revision' => $expected + 1,
            ]);
            return ['id' => $record, 'revision' => $expected + 1];
        }, 3);
    }

    public function archive(
        User $actor, string $company, string $employee, string $record, int $revision,
    ): array {
        return DB::transaction(function () use ($actor, $company, $employee, $record, $revision): array {
            $this->lockEmployee($company, $employee);
            $existing = $this->activeRecord($company, $employee, $record);
            $this->assertRevision($existing, $revision);
            $changed = DB::table('employee_education_records')
                ->where('company_id', $company)->where('employee_id', $employee)
                ->where('id', $record)->whereNull('archived_at')->where('revision', $revision)
                ->update([
                    'archived_at' => now(), 'revision' => $revision + 1, 'updated_at' => now(),
                ]);
            abort_unless($changed === 1, 409, 'Education record changed; reload before saving.');
            $this->audit->record($actor, $company, HrAuditAction::EmployeeEducationArchived, null, [
                'employee_id' => $employee, 'education_record_id' => $record,
                'revision' => $revision + 1,
            ]);
            return ['id' => $record, 'revision' => $revision + 1, 'archived' => true];
        }, 3);
    }

    private function lockEmployee(string $company, string $employee): void
    {
        DB::table('employees')->where('company_id', $company)->where('id', $employee)
            ->lockForUpdate()->firstOrFail();
    }

    private function activeRecord(string $company, string $employee, string $id): object
    {
        return DB::table('employee_education_records')
            ->where('company_id', $company)->where('employee_id', $employee)
            ->where('id', $id)->whereNull('archived_at')->lockForUpdate()->firstOrFail();
    }

    private function assertRevision(object $record, int $expected): void
    {
        abort_unless((int) $record->revision === $expected, 409,
            'Education record changed; reload before saving.');
    }

    private function fields(array $data): array
    {
        return [
            'education_type' => $data['education_type'],
            'organization' => $data['organization'],
            'started_on' => $data['started_on'],
            'ended_on' => $data['ended_on'],
            'specialization' => $data['specialization'],
            'document_reference' => $data['document_reference'],
        ];
    }
}
