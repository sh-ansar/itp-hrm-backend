<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorktimeReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_reads_real_worktime_without_false_zeroes_or_synthetic_calendar_days(): void
    {
        $user = User::factory()->create();
        [$company, $department, $position] = $this->companyScope($user, 'hr_specialist');
        $employee = $this->employee($company, $department, $position, 'EMP-001', 'Person', 'One');

        $empty = $this->actingAs($user)
            ->getJson("/api/v1/companies/{$company}/worktime/timesheet?month=2026-10")
            ->assertOk()
            ->assertJsonPath('data.0.employee_id', $employee)
            ->assertJsonPath('data.0.status', 'missing')
            ->assertJsonPath('data.0.planned_minutes', null)
            ->assertJsonPath('data.0.worked_minutes', null)
            ->assertJsonPath('meta.summary.employees_with_entries', 0)
            ->assertJsonPath('meta.summary.planned_minutes', null);

        $schedule = (string) Str::uuid();
        DB::table('work_schedules')->insert([
            'id' => $schedule,
            'company_id' => $company,
            'code' => 'FIVE_TWO',
            'name' => 'Основной график 5/2',
            'schedule_type' => 'standard',
            'work_time_label' => '09:00–18:00',
            'weekly_minutes' => 2400,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_work_schedules')->insert([
            'id' => (string) Str::uuid(),
            'employee_id' => $employee,
            'work_schedule_id' => $schedule,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('worktime_entries')->insert([
            [
                'id' => (string) Str::uuid(),
                'employee_id' => $employee,
                'work_date' => '2026-10-01',
                'planned_minutes' => 480,
                'worked_minutes' => 480,
                'absence_minutes' => 0,
                'overtime_minutes' => 0,
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'employee_id' => $employee,
                'work_date' => '2026-10-02',
                'planned_minutes' => 480,
                'worked_minutes' => 420,
                'absence_minutes' => 60,
                'overtime_minutes' => 30,
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('production_calendar_days')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $company,
            'work_date' => '2026-10-01',
            'day_type' => 'workday',
            'planned_minutes' => 480,
            'label' => null,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_absences')->insert([
            'id' => (string) Str::uuid(),
            'employee_id' => $employee,
            'absence_type' => 'sick_leave',
            'reason' => 'Временная нетрудоспособность',
            'started_on' => '2026-10-05',
            'ended_on' => '2026-10-06',
            'absence_minutes' => 960,
            'status' => 'recorded',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/companies/{$company}/worktime/timesheet?month=2026-10")
            ->assertOk()
            ->assertJsonPath('data.0.schedule_name', 'Основной график 5/2')
            ->assertJsonPath('data.0.days_recorded', 2)
            ->assertJsonPath('data.0.planned_minutes', 960)
            ->assertJsonPath('data.0.worked_minutes', 900)
            ->assertJsonPath('data.0.absence_minutes', 60)
            ->assertJsonPath('data.0.overtime_minutes', 30)
            ->assertJsonPath('data.0.status', 'attention')
            ->assertJsonPath('meta.summary.employees_with_entries', 1)
            ->assertJsonPath('meta.summary.planned_minutes', 960);

        $this->actingAs($user)
            ->getJson("/api/v1/companies/{$company}/worktime/schedules?as_of=2026-10-13")
            ->assertOk()
            ->assertJsonPath('data.0.id', $schedule)
            ->assertJsonPath('data.0.weekly_minutes', 2400)
            ->assertJsonPath('data.0.employees', 1);

        $this->actingAs($user)
            ->getJson("/api/v1/companies/{$company}/worktime/calendar?month=2026-10")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.recorded_days', 1)
            ->assertJsonPath('meta.expected_days', 31)
            ->assertJsonPath('meta.calendar_complete', false);

        $this->actingAs($user)
            ->getJson("/api/v1/companies/{$company}/worktime/absences?month=2026-10")
            ->assertOk()
            ->assertJsonPath('data.0.employee_id', $employee)
            ->assertJsonPath('data.0.reason', 'Временная нетрудоспособность')
            ->assertJsonPath('data.0.days', 2)
            ->assertJsonPath('data.0.absence_minutes', 960)
            ->assertJsonPath('data.0.status', 'recorded');

        unset($empty);
    }

    public function test_department_manager_only_reads_scoped_employee_worktime(): void
    {
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

        $visibleEmployee = $this->employee($company, $departmentA, $position, 'EMP-A', 'Visible', 'Person');
        $hiddenEmployee = $this->employee($company, $departmentB, $position, 'EMP-B', 'Hidden', 'Person');

        foreach ([$visibleEmployee, $hiddenEmployee] as $index => $employee) {
            DB::table('worktime_entries')->insert([
                'id' => (string) Str::uuid(),
                'employee_id' => $employee,
                'work_date' => '2026-10-01',
                'planned_minutes' => 480,
                'worked_minutes' => 480,
                'absence_minutes' => 0,
                'overtime_minutes' => 0,
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('employee_absences')->insert([
                'id' => (string) Str::uuid(),
                'employee_id' => $employee,
                'absence_type' => 'other',
                'reason' => $index === 0 ? 'Visible absence' : 'Hidden absence',
                'started_on' => '2026-10-03',
                'ended_on' => '2026-10-03',
                'absence_minutes' => 480,
                'status' => 'recorded',
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($user)
            ->getJson("/api/v1/companies/{$company}/worktime/timesheet?month=2026-10")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', $visibleEmployee)
            ->assertJsonMissing(['employee_id' => $hiddenEmployee])
            ->assertJsonPath('meta.summary.employees_with_entries', 1);

        $this->actingAs($user)
            ->getJson("/api/v1/companies/{$company}/worktime/absences?month=2026-10")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'Visible absence')
            ->assertJsonMissing(['reason' => 'Hidden absence']);
    }

    public function test_plain_employee_cannot_read_company_worktime(): void
    {
        $user = User::factory()->create();
        [$company] = $this->companyScope($user, 'employee');

        foreach (['timesheet?month=2026-10', 'schedules?as_of=2026-10-13', 'calendar?month=2026-10', 'absences?month=2026-10'] as $suffix) {
            $this->actingAs($user)
                ->getJson("/api/v1/companies/{$company}/worktime/{$suffix}")
                ->assertForbidden();
        }
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
}
