<?php

namespace Tests\Feature;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class HrAuditImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private function appendAuditEvent(): int
    {
        $companyId = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $companyId, 'name' => 'Synthetic test company']);
        $actor = User::factory()->create();

        app(HrAuditWriter::class)->record(
            $actor,
            $companyId,
            HrAuditAction::EmployeesListed,
            null,
            ['current_page' => 1, 'returned_count' => 0],
        );

        return (int) DB::table('hr_audit_events')->where('company_id', $companyId)->value('id');
    }

    /**
     * RefreshDatabase runs each test in an outer transaction. A nested
     * transaction creates a PostgreSQL savepoint so a rejected SQL statement
     * does not poison the outer test transaction.
     */
    private function assertMutationRejected(callable $operation): void
    {
        try {
            DB::transaction(static function () use ($operation): void {
                $operation();
            });

            $this->fail('The audit storage allowed a destructive mutation.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('hr_audit_events is append-only', $exception->getMessage());
        }
    }

    public function test_normal_audit_insertion_still_works(): void
    {
        $id = $this->appendAuditEvent();

        $this->assertDatabaseHas('hr_audit_events', [
            'id' => $id,
            'action' => HrAuditAction::EmployeesListed->value,
        ]);
    }

    public function test_existing_audit_action_cannot_be_modified(): void
    {
        $id = $this->appendAuditEvent();

        $this->assertMutationRejected(static function () use ($id): void {
            DB::table('hr_audit_events')
                ->where('id', $id)
                ->update(['action' => 'company.role_revoked']);
        });

        $this->assertDatabaseHas('hr_audit_events', [
            'id' => $id,
            'action' => HrAuditAction::EmployeesListed->value,
        ]);
    }

    public function test_even_noop_audit_update_is_rejected(): void
    {
        $id = $this->appendAuditEvent();

        $this->assertMutationRejected(static function () use ($id): void {
            DB::table('hr_audit_events')
                ->where('id', $id)
                ->update(['action' => HrAuditAction::EmployeesListed->value]);
        });

        $this->assertDatabaseCount('hr_audit_events', 1);
    }

    public function test_existing_audit_record_cannot_be_deleted(): void
    {
        $id = $this->appendAuditEvent();

        $this->assertMutationRejected(static function () use ($id): void {
            DB::table('hr_audit_events')->where('id', $id)->delete();
        });

        $this->assertDatabaseHas('hr_audit_events', [
            'id' => $id,
            'action' => HrAuditAction::EmployeesListed->value,
        ]);
    }

    public function test_bulk_audit_deletion_cannot_remove_records(): void
    {
        $this->appendAuditEvent();

        $this->assertMutationRejected(static function (): void {
            DB::table('hr_audit_events')->delete();
        });

        $this->assertDatabaseCount('hr_audit_events', 1);
    }

    public function test_postgresql_truncate_is_rejected_and_sqlite_guards_remain_present(): void
    {
        $this->appendAuditEvent();

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->assertMutationRejected(static function (): void {
                DB::statement('TRUNCATE TABLE hr_audit_events');
            });
        } else {
            $this->assertSame('sqlite', DB::connection()->getDriverName());
            $names = DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->whereIn('name', ['hrm_audit_no_update', 'hrm_audit_no_delete'])
                ->pluck('name')
                ->all();

            $this->assertCount(2, $names);
        }

        $this->assertDatabaseCount('hr_audit_events', 1);
    }
}
