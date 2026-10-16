<?php

namespace App\Domain\Leave;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Formal leave planning state machine.
 *
 * Approved requests are projected once into the existing employee_leaves table.
 * Planned dates remain distinct from actual attendance; no automatic completion.
 * All writes and their audit records commit in a single transaction.
 */
final class LeavePlanWorkflow
{
    public function __construct(
        private readonly LeaveDayCounter $counter,
        private readonly HrAuditWriter $audit,
    ) {}

    public function create(User $actor, string $company, array $input): array
    {
        return DB::transaction(function () use ($actor, $company, $input): array {
            $this->lockCompany($company);
            $this->assertEmployee($company, $input['employee_id']);
            $days = $this->counter->count(
                $company, $input['leave_type_id'], $input['planned_started_on'], $input['planned_ended_on'],
            );
            $this->assertNoOverlap($company, $input['employee_id'], $input['planned_started_on'], $input['planned_ended_on']);
            $id = (string) Str::uuid();
            $values = [
                'id' => $id,
                'company_id' => $company,
                'employee_id' => $input['employee_id'],
                'leave_type_id' => $input['leave_type_id'],
                'planned_started_on' => $input['planned_started_on'],
                'planned_ended_on' => $input['planned_ended_on'],
                'planned_days' => $days,
                'status' => 'draft',
                'note' => $input['note'],
                'revision' => 1,
                'created_by_user_id' => $actor->getAuthIdentifier(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            DB::table('leave_requests')->insert($values);
            $this->audit->record($actor, $company, HrAuditAction::LeaveRequestCreated, null, [
                'request_id' => $id, 'employee_id' => $input['employee_id'], 'revision' => 1,
            ]);

            return $this->serialize($values);
        }, 3);
    }

    public function update(User $actor, string $company, string $id, array $input): array
    {
        return DB::transaction(function () use ($actor, $company, $id, $input): array {
            $this->lockCompany($company);
            $record = $this->loadLocked($company, $id);
            $this->assertRevision($record, (int) $input['revision']);
            abort_unless($record->status === 'draft', 409, 'Only draft leave requests can be edited.');
            $this->assertEmployee($company, $input['employee_id']);
            $days = $this->counter->count(
                $company, $input['leave_type_id'], $input['planned_started_on'], $input['planned_ended_on'],
            );
            $this->assertNoOverlap($company, $input['employee_id'], $input['planned_started_on'], $input['planned_ended_on'], $id);
            $updates = [
                'employee_id' => $input['employee_id'],
                'leave_type_id' => $input['leave_type_id'],
                'planned_started_on' => $input['planned_started_on'],
                'planned_ended_on' => $input['planned_ended_on'],
                'planned_days' => $days,
                'note' => $input['note'],
                'revision' => (int) $record->revision + 1,
                'updated_at' => now(),
            ];
            $this->updateRevision($company, $id, (int) $record->revision, $updates);
            $this->audit->record($actor, $company, HrAuditAction::LeaveRequestUpdated, null, [
                'request_id' => $id, 'revision' => $updates['revision'],
            ]);

            return $this->serialize(array_merge((array) $record, $updates));
        }, 3);
    }

    public function submit(User $actor, string $company, string $id, int $expected): array
    {
        return $this->transition($actor, $company, $id, $expected, 'draft', 'submitted');
    }

    public function cancel(User $actor, string $company, string $id, int $expected): array
    {
        return DB::transaction(function () use ($actor, $company, $id, $expected): array {
            $this->lockCompany($company);
            $record = $this->loadLocked($company, $id);
            $this->assertRevision($record, $expected);
            abort_unless(in_array($record->status, ['draft', 'submitted'], true), 409,
                'Approved leave cannot be cancelled without a formal reversal.');
            $updated = [
                'status' => 'cancelled',
                'revision' => $expected + 1,
                'updated_at' => now(),
            ];
            $this->updateRevision($company, $id, $expected, $updated);
            $this->audit->record($actor, $company, HrAuditAction::LeaveRequestCancelled, null, [
                'request_id' => $id, 'revision' => $expected + 1,
            ]);
            return $this->serialize(array_merge((array) $record, $updated));
        }, 3);
    }

    public function decide(User $actor, string $company, string $id, int $expected, string $decision, ?string $comment): array
    {
        return DB::transaction(function () use ($actor, $company, $id, $expected, $decision, $comment): array {
            $this->lockCompany($company);
            $record = $this->loadLocked($company, $id);
            $this->assertRevision($record, $expected);
            abort_unless($record->status === 'submitted', 409, 'Only submitted leave requests can be decided.');
            // Four-eyes control is enforced by the server, not inferred from a button.
            abort_if((int) $record->created_by_user_id === (int) $actor->getAuthIdentifier(), 403,
                'A person cannot approve or reject their own leave request.');
            abort_if(DB::table('employee_user_links')->where('company_id', $company)
                ->where('user_id', $actor->getAuthIdentifier())->where('employee_id', $record->employee_id)->exists(), 403,
                'A person cannot decide leave for their own employee record.');

            $publishedLeave = null;
            if ($decision === 'approved') {
                $this->assertEmployee($company, $record->employee_id);
                $this->assertNoOverlap(
                    $company, $record->employee_id, $record->planned_started_on, $record->planned_ended_on, $id,
                );
                $publishedLeave = (string) Str::uuid();
                DB::table('employee_leaves')->insert([
                    'id' => $publishedLeave,
                    'employee_id' => $record->employee_id,
                    'leave_type_id' => $record->leave_type_id,
                    'planned_started_on' => $record->planned_started_on,
                    'planned_ended_on' => $record->planned_ended_on,
                    'planned_days' => $record->planned_days,
                    'actual_started_on' => null,
                    'actual_ended_on' => null,
                    'actual_days' => null,
                    'revision' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $updates = [
                'status' => $decision,
                'revision' => $expected + 1,
                'decided_by_user_id' => $actor->getAuthIdentifier(),
                'decided_at' => now(),
                'decision_comment' => $comment,
                'published_leave_id' => $publishedLeave,
                'updated_at' => now(),
            ];
            $this->updateRevision($company, $id, $expected, $updates);
            $this->audit->record($actor, $company, $decision === 'approved'
                ? HrAuditAction::LeaveRequestApproved : HrAuditAction::LeaveRequestRejected, null, [
                    'request_id' => $id, 'revision' => $expected + 1,
                    'published_leave_id' => $publishedLeave,
                ]);

            return $this->serialize(array_merge((array) $record, $updates));
        }, 3);
    }

    private function transition(
        User $actor, string $company, string $id, int $expected, string $from, string $to,
    ): array {
        return DB::transaction(function () use ($actor, $company, $id, $expected, $from, $to): array {
            $this->lockCompany($company);
            $record = $this->loadLocked($company, $id);
            $this->assertRevision($record, $expected);
            abort_unless($record->status === $from, 409, 'Invalid leave workflow transition.');
            $this->assertNoOverlap(
                $company, $record->employee_id, $record->planned_started_on, $record->planned_ended_on, $id,
            );
            $updates = ['status' => $to, 'revision' => $expected + 1,
                'submitted_at' => now(), 'updated_at' => now()];
            $this->updateRevision($company, $id, $expected, $updates);
            $this->audit->record($actor, $company, HrAuditAction::LeaveRequestSubmitted, null, [
                'request_id' => $id, 'revision' => $expected + 1,
            ]);
            return $this->serialize(array_merge((array) $record, $updates));
        }, 3);
    }

    private function assertNoOverlap(string $company, string $employee, string $from, string $to, ?string $excluding = null): void
    {
        $requests = DB::table('leave_requests')
            ->where('company_id', $company)
            ->where('employee_id', $employee)
            ->whereIn('status', ['draft', 'submitted', 'approved'])
            ->where('planned_started_on', '<=', $to)
            ->where('planned_ended_on', '>=', $from);
        if ($excluding !== null) $requests->where('id', '<>', $excluding);
        if ($requests->exists()) {
            throw ValidationException::withMessages(['planned_started_on' => 'Leave overlaps an existing active request.']);
        }

        if (DB::table('employee_leaves')
            ->where('employee_id', $employee)
            ->where('planned_started_on', '<=', $to)
            ->where('planned_ended_on', '>=', $from)
            ->exists()) {
            throw ValidationException::withMessages(['planned_started_on' => 'Leave overlaps a confirmed leave period.']);
        }
    }

    private function assertEmployee(string $company, string $employee): void
    {
        $exists = DB::table('employees')->where('company_id', $company)->where('id', $employee)->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['employee_id' => 'Employee does not belong to the selected company.']);
        }
    }

    private function lockCompany(string $company): void
    {
        DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
    }

    private function loadLocked(string $company, string $id): object
    {
        return DB::table('leave_requests')->where('company_id', $company)
            ->where('id', $id)->lockForUpdate()->firstOrFail();
    }

    private function assertRevision(object $record, int $revision): void
    {
        abort_unless((int) $record->revision === $revision, 409,
            'Leave request was edited by someone else; reload before saving.');
    }

    private function updateRevision(string $company, string $id, int $current, array $updates): void
    {
        $count = DB::table('leave_requests')->where('company_id', $company)
            ->where('id', $id)->where('revision', $current)->update($updates);
        abort_unless($count === 1, 409, 'Leave request revision is stale.');
    }

    private function serialize(array $row): array
    {
        return [
            'id' => $row['id'],
            'company_id' => $row['company_id'],
            'employee_id' => $row['employee_id'],
            'leave_type_id' => $row['leave_type_id'],
            'planned_started_on' => $row['planned_started_on'],
            'planned_ended_on' => $row['planned_ended_on'],
            'planned_days' => (int) $row['planned_days'],
            'status' => $row['status'],
            'revision' => (int) $row['revision'],
            'note' => $row['note'] ?? null,
            'decision_comment' => $row['decision_comment'] ?? null,
            'published_leave_id' => $row['published_leave_id'] ?? null,
        ];
    }
}
