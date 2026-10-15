<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LeaveReadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_hr_reads_planned_actual_and_shifted_leave_without_inventing_completion(): void
    {
        Carbon::setTestNow('2026-10-13 12:00:00');

        $user = User::factory()->create();
        [$company, $department, $position] = $this->companyScope($user, 'hr_specialist');
        $employeeA = $this->employee($company, $department, $position, 'EMP-001', 'Person', 'One');
        $employeeB = $this->employee($company, $department, $position, 'EMP-002', 'Person', 'Two');

        $annual = $this->leaveType(
            $company,
            'annual',
            'Ежегодный оплачиваемый отпуск',
            24,
            'calendar_days',
        );

        // Completed and confirmed: planned and actual dates match.
        $this->leave($employeeA, $annual, [
            'planned_started_on' => '2026-09-01',
            'planned_ended_on' => '2026-09-05',
            'planned_days' => 5,
            'actual_started_on' => '2026-09-01',
            'actual_ended_on' => '2026-09-05',
            'actual_days' => 5,
        ]);

        // Ongoing and shifted: actual dates preserve the original plan.
        $this->leave($employeeB, $annual, [
            'planned_started_on' => '2026-10-10',
            'planned_ended_on' => '2026-10-20',
            'planned_days' => 11,
            'actual_started_on' => '2026-10-12',
            'actual_ended_on' => '2026-10-22',
            'actual_days' => 11,
        ]);

        // Planned in the future. No actual confirmation => never auto-completed.
        $this->leave($employeeA, $annual, [
            'planned_started_on' => '2026-11-01',
            'planned_ended_on' => '2026-11-14',
            'planned_days' => 14,
            'actual_started_on' => null,
            'actual_ended_on' => null,
            'actual_days' => null,
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/v1/companies/{$company}/leaves?year=2026")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.summary.total', 3)
            ->assertJsonPath('meta.summary.employees', 2)
            ->assertJsonPath('meta.summary.days', 30)
            ->assertJsonPath('meta.summary.planned', 1)
            ->assertJsonPath('meta.summary.ongoing', 1)
            ->assertJsonPath('meta.summary.completed', 1)
            ->assertJsonPath('meta.summary.shifted', 1)
            ->assertJsonPath('meta.leave_writes_enabled', false);

        $rows = $response->json('data');

        $this->assertSame('completed', $rows[0]['status']);
        $this->assertSame('on_schedule', $rows[0]['schedule_state']);
        $this->assertSame('2026-09-01', $rows[0]['started_on']);
        $this->assertSame('Ежегодный оплачиваемый отпуск', $rows[0]['leave_type_name']);
        $this->assertSame(24, $rows[0]['annual_days']);

        $this->assertSame('ongoing', $rows[1]['status']);
        $this->assertSame('shifted', $rows[1]['schedule_state']);
        $this->assertSame('2026-10-10', $rows[1]['planned_started_on']);
        $this->assertSame('2026-10-12', $rows[1]['actual_started_on']);
        $this->assertSame('2026-10-12', $rows[1]['started_on']);
        $this->assertSame('Operations', $rows[1]['department_name']);
        $this->assertSame('Operator', $rows[1]['position_name']);

        $this->assertSame('planned', $rows[2]['status']);
        $this->assertSame('on_schedule', $rows[2]['schedule_state']);
        $this->assertNull($rows[2]['actual_started_on']);
        $this->assertSame('2026-11-01', $rows[2]['started_on']);
    }

    public function test_department_manager_only_reads_leave_records_in_allowed_scope(): void
    {
        Carbon::setTestNow('2026-10-13 12:00:00');

        $user = User::factory()->create();
        $company = (string) Str::uuid();
        $departmentA = (string) Str::uuid();
        $departmentB = (string) Str::uuid();
        $position = (string) Str::uuid();

        DB::table('companies')->insert(['id' => $company, 'name' => 'Scoped Company']);
        DB::table('departments')->insert([
            ['id' => $departmentA, 'company_id' => $company, 'name' => 'Allowed'],
            ['id' => $departmentB, 'company_id' => $company, 'name' => 'Hidden'],
        ]);
        DB::table('positions')->insert([
            'id' => $position,
            'company_id' => $company,
            'name' => 'Operator',
        ]);
        DB::table('company_user_roles')->insert([
            'company_id' => $company,
            'user_id' => $user->id,
            'role_code' => 'department_manager',
        ]);
        DB::table('department_access_scopes')->insert([
            'company_id' => $company,
            'user_id' => $user->id,
            'department_id' => $departmentA,
        ]);

        $visibleEmployee = $this->employee(
            $company,
            $departmentA,
            $position,
            'EMP-A',
            'Visible',
            'Person',
        );
        $hiddenEmployee = $this->employee(
            $company,
            $departmentB,
            $position,
            'EMP-B',
            'Hidden',
            'Person',
        );
        $annual = $this->leaveType($company, 'annual', 'Annual', 24, 'calendar_days');

        $this->leave($visibleEmployee, $annual, [
            'planned_started_on' => '2026-11-01',
            'planned_ended_on' => '2026-11-05',
            'planned_days' => 5,
        ]);
        $this->leave($hiddenEmployee, $annual, [
            'planned_started_on' => '2026-11-10',
            'planned_ended_on' => '2026-11-14',
            'planned_days' => 5,
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/companies/{$company}/leaves?year=2026")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', $visibleEmployee)
            ->assertJsonMissing(['employee_id' => $hiddenEmployee])
            ->assertJsonPath('meta.summary.total', 1)
            ->assertJsonPath('meta.summary.employees', 1);
    }

    public function test_manager_sees_absence_period_without_protected_medical_details(): void
    {
        Carbon::setTestNow('2026-10-13 12:00:00');
        $manager = User::factory()->create();
        [$company, $department, $position] = $this->companyScope($manager, 'department_manager');
        DB::table('department_access_scopes')->insert([
            'company_id' => $company, 'user_id' => $manager->id, 'department_id' => $department,
        ]);
        $employee = $this->employee($company, $department, $position, 'SAFE-01', 'Protected', 'Worker');
        $type = $this->leaveType($company, 'medical_private', 'Sensitive medical leave', null, 'calendar_days');
        DB::table('leave_types')->where('id', $type)->update(['category' => 'medical']);
        $this->leave($employee, $type, [
            'planned_started_on' => '2026-11-01', 'planned_ended_on' => '2026-11-05',
            'planned_days' => 5,
        ]);

        $this->actingAs($manager)->getJson("/api/v1/companies/{$company}/leaves?year=2026")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', $employee)
            ->assertJsonPath('data.0.leave_type_name', 'Restricted leave')
            ->assertJsonPath('data.0.leave_type_code', 'restricted')
            ->assertJsonPath('data.0.category', 'restricted')
            ->assertJsonMissing(['leave_type_name' => 'Sensitive medical leave']);
        $this->actingAs($manager)->getJson("/api/v1/companies/{$company}/leave-types")
            ->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.supported_categories.0', 'ordinary');

        $hr = User::factory()->create();
        DB::table('company_user_roles')->insert([
            'company_id' => $company, 'user_id' => $hr->id, 'role_code' => 'hr_specialist',
        ]);
        $this->actingAs($hr)->getJson("/api/v1/companies/{$company}/leaves?year=2026")
            ->assertOk()->assertJsonPath('data.0.leave_type_name', 'Sensitive medical leave')
            ->assertJsonPath('data.0.category', 'medical');
    }

    public function test_plain_employee_cannot_read_company_leave_schedule(): void
    {
        $user = User::factory()->create();
        [$company] = $this->companyScope($user, 'employee');

        $this->actingAs($user)
            ->getJson("/api/v1/companies/{$company}/leaves?year=2026")
            ->assertForbidden();
    }

    private function companyScope(User $user, string $role): array
    {
        $company = (string) Str::uuid();
        $department = (string) Str::uuid();
        $position = (string) Str::uuid();

        DB::table('companies')->insert(['id' => $company, 'name' => 'Example']);
        DB::table('departments')->insert([
            'id' => $department,
            'company_id' => $company,
            'name' => 'Operations',
        ]);
        DB::table('positions')->insert([
            'id' => $position,
            'company_id' => $company,
            'name' => 'Operator',
        ]);
        DB::table('company_user_roles')->insert([
            'company_id' => $company,
            'user_id' => $user->id,
            'role_code' => $role,
        ]);

        return [$company, $department, $position];
    }

    private function employee(
        string $company,
        string $department,
        string $position,
        string $personnelNumber,
        string $lastName,
        string $firstName,
    ): string {
        $employee = (string) Str::uuid();

        DB::table('employees')->insert([
            'id' => $employee,
            'company_id' => $company,
            'personnel_number' => $personnelNumber,
            'last_name' => $lastName,
            'first_name' => $firstName,
            'middle_name' => null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_assignments')->insert([
            'id' => (string) Str::uuid(),
            'employee_id' => $employee,
            'department_id' => $department,
            'position_id' => $position,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employee;
    }

    private function leaveType(
        string $company,
        string $code,
        string $name,
        ?int $annualDays,
        ?string $balanceMethod,
    ): string {
        $id = (string) Str::uuid();

        DB::table('leave_types')->insert([
            'id' => $id,
            'company_id' => $company,
            'code' => $code,
            'name' => $name,
            'annual_days' => $annualDays,
            'balance_method' => $balanceMethod,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function leave(string $employee, string $leaveType, array $values): string
    {
        $id = (string) Str::uuid();

        DB::table('employee_leaves')->insert([
            'id' => $id,
            'employee_id' => $employee,
            'leave_type_id' => $leaveType,
            'planned_started_on' => $values['planned_started_on'],
            'planned_ended_on' => $values['planned_ended_on'],
            'planned_days' => $values['planned_days'],
            'actual_started_on' => $values['actual_started_on'] ?? null,
            'actual_ended_on' => $values['actual_ended_on'] ?? null,
            'actual_days' => $values['actual_days'] ?? null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
