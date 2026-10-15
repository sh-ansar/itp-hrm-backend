<?php

namespace App\Domain\Compensation;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR bonus proposal lifecycle. Approval means authorized HR decision only:
 * it does not generate a payroll posting, ledger entry or cash movement.
 *
 * Transactions cover data and immutable audit; company row locking
 * serializes client retries and employee bonus revisions.
 */
final class EmployeeBonusWorkflow
{
    public function __construct(
        private readonly BonusAuthorization $authorization,
        private readonly HrAuditWriter $audit,
    ) {}

    public function create(User $actor, string $company, array $input): array
    {
        return DB::transaction(function () use ($actor, $company, $input): array {
            $this->lockCompany($company);
            $this->requireManager($actor, $company);
            $this->requireCompanyEmployee($company, $input['employee_id']);

            $normalized = $this->inputValues($input);
            $existing = DB::table('employee_bonuses')->where('company_id', $company)
                ->where('request_key', $input['request_key'])->first();

            if ($existing) {
                // A key belongs to exactly one original payload, not to subsequent edits.
                foreach ($normalized as $key => $value) {
                    $previous = (string) ($existing->$key ?? '');
                    $expected = (string) ($value ?? '');
                    if ($key === 'amount') {
                        $previous = $this->normalizeAmount($previous);
                        $expected = $this->normalizeAmount($expected);
                    }
                    abort_unless($previous === $expected, 409,
                        'Idempotency key belongs to a different bonus proposal.');
                }
                return $this->serialize($existing);
            }

            $id = (string) Str::uuid();
            $values = $normalized + [
                'id' => $id,
                'company_id' => $company,
                'request_key' => $input['request_key'],
                'status' => 'draft',
                'revision' => 1,
                'created_by_user_id' => $actor->getAuthIdentifier(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            DB::table('employee_bonuses')->insert($values);
            $this->audit->record($actor, $company, HrAuditAction::EmployeeBonusCreated, null, [
                'bonus_id' => $id, 'employee_id' => $input['employee_id'], 'revision' => 1,
            ]);

            return $this->serialize($values);
        }, 3);
    }

    public function update(User $actor, string $company, string $id, array $input): array
    {
        return DB::transaction(function () use ($actor, $company, $id, $input): array {
            $this->lockCompany($company);
            $this->requireManager($actor, $company);
            $record = $this->loadLocked($company, $id);
            $this->requireRevision($record, (int) $input['revision']);
            abort_unless($record->status === 'draft', 409, 'Only draft bonuses can be edited.');
            $this->requireCompanyEmployee($company, $input['employee_id']);

            $updates = $this->inputValues($input) + [
                'revision' => (int) $record->revision + 1,
                'updated_at' => now(),
            ];
            $this->updateRevision($company, $id, (int) $record->revision, $updates);
            $this->audit->record($actor, $company, HrAuditAction::EmployeeBonusUpdated, null, [
                'bonus_id' => $id, 'revision' => $updates['revision'],
            ]);

            return $this->serialize(array_merge((array) $record, $updates));
        }, 3);
    }

    public function submit(User $actor, string $company, string $id, int $revision): array
    {
        return $this->transition($actor, $company, $id, $revision, ['draft'], 'submitted',
            HrAuditAction::EmployeeBonusSubmitted);
    }

    public function cancel(User $actor, string $company, string $id, int $revision): array
    {
        return $this->transition($actor, $company, $id, $revision, ['draft', 'submitted'], 'cancelled',
            HrAuditAction::EmployeeBonusCancelled);
    }

    public function decide(
        User $actor,
        string $company,
        string $id,
        int $revision,
        string $decision,
        ?string $comment,
    ): array {
        return DB::transaction(function () use ($actor, $company, $id, $revision, $decision, $comment): array {
            $this->lockCompany($company);
            abort_unless($this->authorization->canApprove($actor, $company), 403);
            $record = $this->loadLocked($company, $id);
            $this->requireRevision($record, $revision);
            abort_unless($record->status === 'submitted', 409, 'Only submitted bonuses may be decided.');
            // Separation of duties: no self-approval by the creator or submitter.
            abort_if((int) $record->created_by_user_id === (int) $actor->getAuthIdentifier()
                || (int) $record->submitted_by_user_id === (int) $actor->getAuthIdentifier(), 403,
                'The initiator cannot approve their own bonus.');
            abort_unless(in_array($decision, ['approved', 'rejected'], true), 422);
            if ($decision === 'rejected' && ! trim((string) $comment)) {
                throw ValidationException::withMessages(['comment' => 'Rejection reason is required.']);
            }

            $updates = [
                'status' => $decision,
                'revision' => $revision + 1,
                'decided_by_user_id' => $actor->getAuthIdentifier(),
                'decided_at' => now(),
                'decision_comment' => trim((string) $comment) ?: null,
                'updated_at' => now(),
            ];
            $this->updateRevision($company, $id, $revision, $updates);
            $this->audit->record($actor, $company,
                $decision === 'approved' ? HrAuditAction::EmployeeBonusApproved : HrAuditAction::EmployeeBonusRejected,
                null, ['bonus_id' => $id, 'revision' => $revision + 1]);

            return $this->serialize(array_merge((array) $record, $updates));
        }, 3);
    }

    private function transition(
        User $actor, string $company, string $id, int $revision, array $from, string $to, HrAuditAction $action,
    ): array {
        return DB::transaction(function () use ($actor, $company, $id, $revision, $from, $to, $action): array {
            $this->lockCompany($company);
            $this->requireManager($actor, $company);
            $record = $this->loadLocked($company, $id);
            $this->requireRevision($record, $revision);
            abort_unless(in_array($record->status, $from, true), 409,
                'This bonus can no longer be moved to the requested status.');

            $updates = [
                'status' => $to,
                'revision' => $revision + 1,
                'updated_at' => now(),
            ];
            if ($to === 'submitted') {
                $updates['submitted_by_user_id'] = $actor->getAuthIdentifier();
                $updates['submitted_at'] = now();
            }
            $this->updateRevision($company, $id, $revision, $updates);
            $this->audit->record($actor, $company, $action, null, [
                'bonus_id' => $id, 'revision' => $revision + 1,
            ]);

            return $this->serialize(array_merge((array) $record, $updates));
        }, 3);
    }

    private function lockCompany(string $company): void
    {
        abort_unless(DB::table('companies')->where('id', $company)->lockForUpdate()->exists(), 404);
    }

    private function requireManager(User $actor, string $company): void
    {
        abort_unless($this->authorization->canManage($actor, $company), 403);
    }

    private function requireCompanyEmployee(string $company, string $employee): void
    {
        if (! DB::table('employees')->where('company_id', $company)->where('id', $employee)->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'Employee is not in the selected company.']);
        }
    }

    private function loadLocked(string $company, string $id): object
    {
        $row = DB::table('employee_bonuses')->where('company_id', $company)
            ->where('id', $id)->lockForUpdate()->first();
        abort_unless($row, 404);

        return $row;
    }

    private function requireRevision(object $record, int $revision): void
    {
        abort_unless((int) $record->revision === $revision, 409, 'Bonus record has changed. Refresh the page.');
    }

    private function updateRevision(string $company, string $id, int $revision, array $updates): void
    {
        $count = DB::table('employee_bonuses')->where('company_id', $company)
            ->where('id', $id)->where('revision', $revision)->update($updates);
        abort_unless($count === 1, 409, 'Bonus record has changed. Refresh the page.');
    }

    private function inputValues(array $input): array
    {
        return [
            'employee_id' => $input['employee_id'],
            'bonus_kind' => $input['bonus_kind'],
            'award_on' => $input['award_on'],
            'amount' => $this->normalizeAmount((string) $input['amount']),
            'currency' => $input['currency'],
            'basis_reference' => trim($input['basis_reference']),
            'note' => isset($input['note']) ? trim($input['note']) ?: null : null,
        ];
    }

    private function normalizeAmount(string $amount): string
    {
        [$units, $cents] = array_pad(explode('.', $amount, 2), 2, '');
        return (ltrim($units, '0') ?: '0').'.'.str_pad($cents, 2, '0');
    }

    private function serialize(object|array $row): array
    {
        $item = (array) $row;
        // SQLite exposes DECIMAL as a number in feature tests; API always serves strings.
        $item['amount'] = $this->normalizeAmount((string) $item['amount']);

        return $item;
    }
}
