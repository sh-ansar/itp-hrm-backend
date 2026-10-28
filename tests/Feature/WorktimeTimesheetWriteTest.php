<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorktimeTimesheetWriteTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $role = 'company_admin'): array
    {
        $user = User::factory()->create();
        $company = (string) Str::uuid();
        $employee = (string) Str::uuid();

        DB::table('companies')->insert(['id' => $company, 'name' => 'Synthetic Company']);
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id,
            'company_id' => $company,
            'role_code' => $role,
        ]);
        DB::table('employees')->insert([
            'id' => $employee,
            'company_id' => $company,
            'personnel_number' => 'SYN-001',
            'last_name' => 'Synthetic',
            'first_name' => 'Employee',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['2026-10-15', '2026-10-16'] as $date) {
            DB::table('production_calendar_days')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $company,
                'work_date' => $date,
                'day_type' => 'workday',
                'planned_minutes' => 480,
                'label' => null,
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$user, $company, $employee, "/api/v1/companies/{$company}/worktime/timesheet/{$employee}"];
    }

    private function day(string $date = '2026-10-15', ?int $revision = null, array $overrides = []): array
    {
        return array_merge([
            'date' => $date,
            'worked_minutes' => 480,
            'absence_minutes' => 0,
            'overtime_minutes' => 0,
            'revision' => $revision,
        ], $overrides);
    }

    public function test_timesheet_writes_are_fail_closed(): void
    {
        [$user, , , $url] = $this->fixture();

        $this->actingAs($user)->getJson($url.'?month=2026-10')->assertNotFound();
        $this->actingAs($user)->putJson($url, [
            'month' => '2026-10',
            'days' => [$this->day()],
        ])->assertNotFound();

        $this->assertDatabaseCount('worktime_entries', 0);
    }

    public function test_company_admin_and_hr_can_edit_but_employee_cannot(): void
    {
        config()->set('hrm.worktime_writes_enabled', true);

        [$admin, , , $adminUrl] = $this->fixture('company_admin');
        $this->actingAs($admin)->putJson($adminUrl, [
            'month' => '2026-10',
            'days' => [$this->day()],
        ])->assertOk()->assertJsonPath('meta.created_count', 1);

        [$hr, , , $hrUrl] = $this->fixture('hr_specialist');
        $this->actingAs($hr)->putJson($hrUrl, [
            'month' => '2026-10',
            'days' => [$this->day()],
        ])->assertOk();

        [$employeeUser, , , $employeeUrl] = $this->fixture('employee');
        $this->actingAs($employeeUser)->getJson($employeeUrl.'?month=2026-10')->assertForbidden();
        $this->actingAs($employeeUser)->putJson($employeeUrl, [
            'month' => '2026-10',
            'days' => [$this->day()],
        ])->assertForbidden();
    }

    public function test_create_read_update_and_audit_daily_entries(): void
    {
        config()->set('hrm.worktime_writes_enabled', true);
        [$user, $company, $employee, $url] = $this->fixture();

        $this->actingAs($user)->putJson($url, [
            'month' => '2026-10',
            'days' => [
                $this->day('2026-10-15'),
                $this->day('2026-10-16', null, ['worked_minutes' => 420, 'overtime_minutes' => 60]),
            ],
        ])->assertOk()
            ->assertJsonPath('meta.created_count', 2)
            ->assertJsonPath('meta.updated_count', 0)
            ->assertJsonPath('data.0.revision', 1)
            ->assertJsonPath('data.1.overtime_minutes', 60);

        $this->actingAs($user)->getJson($url.'?month=2026-10')
            ->assertOk()
            ->assertJsonPath('meta.can_edit', true)
            ->assertJsonPath('data.0.worked_minutes', 480)
            ->assertJsonPath('data.1.worked_minutes', 420);

        $this->actingAs($user)->putJson($url, [
            'month' => '2026-10',
            'days' => [
                $this->day('2026-10-15', 1, ['worked_minutes' => 360, 'absence_minutes' => 120]),
            ],
        ])->assertOk()
            ->assertJsonPath('meta.updated_count', 1)
            ->assertJsonPath('data.0.revision', 2)
            ->assertJsonPath('data.0.absence_minutes', 120);

        $this->assertDatabaseHas('worktime_entries', [
            'employee_id' => $employee,
            'work_date' => '2026-10-15',
            'planned_minutes' => 480,
            'worked_minutes' => 360,
            'absence_minutes' => 120,
            'revision' => 2,
        ]);
        $this->assertSame(2, DB::table('hr_audit_events')
            ->where('company_id', $company)
            ->where('action', 'worktime.entries_upserted')
            ->count());
    }

    public function test_future_actual_work_absence_and_overtime_are_denied_atomically(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15)->startOfDay());
        config()->set('hrm.worktime_writes_enabled', true);
        [$user, , $employee, $url] = $this->fixture();

        foreach (['worked_minutes', 'absence_minutes', 'overtime_minutes'] as $field) {
            $future = $this->day('2026-10-16', null, [
                'worked_minutes' => 0,
                'absence_minutes' => 0,
                'overtime_minutes' => 0,
                $field => 60,
            ]);
            $this->actingAs($user)->putJson($url, [
                'month' => '2026-10',
                'days' => [$this->day('2026-10-15'), $future],
            ])->assertUnprocessable()->assertJsonValidationErrors(['days.1.date']);
            $this->assertDatabaseCount('worktime_entries', 0);
        }

        // A correction to clear previously recorded future values is allowed.
        $this->actingAs($user)->putJson($url, [
            'month' => '2026-10',
            'days' => [$this->day('2026-10-16', null, [
                'worked_minutes' => null, 'absence_minutes' => null, 'overtime_minutes' => null,
            ])],
        ])->assertOk()->assertJsonPath('meta.created_count', 1);
        $this->assertDatabaseHas('worktime_entries', [
            'employee_id' => $employee, 'work_date' => '2026-10-16',
            'worked_minutes' => null, 'absence_minutes' => null, 'overtime_minutes' => null,
        ]);
    }

    public function test_stale_revision_rejects_entire_batch(): void
    {
        config()->set('hrm.worktime_writes_enabled', true);
        [$user, , $employee, $url] = $this->fixture();

        $this->actingAs($user)->putJson($url, [
            'month' => '2026-10',
            'days' => [$this->day('2026-10-15')],
        ])->assertOk();

        $this->actingAs($user)->putJson($url, [
            'month' => '2026-10',
            'days' => [
                $this->day('2026-10-15', 7, ['worked_minutes' => 300]),
                $this->day('2026-10-16', null),
            ],
        ])->assertStatus(409);

        $this->assertDatabaseHas('worktime_entries', [
            'employee_id' => $employee,
            'work_date' => '2026-10-15',
            'worked_minutes' => 480,
            'revision' => 1,
        ]);
        $this->assertDatabaseMissing('worktime_entries', [
            'employee_id' => $employee,
            'work_date' => '2026-10-16',
        ]);
    }

    public function test_missing_calendar_and_invalid_absence_are_rejected(): void
    {
        config()->set('hrm.worktime_writes_enabled', true);
        [$user, $company, , $url] = $this->fixture();

        DB::table('production_calendar_days')
            ->where('company_id', $company)
            ->where('work_date', '2026-10-16')
            ->delete();

        $this->actingAs($user)->putJson($url, [
            'month' => '2026-10',
            'days' => [$this->day('2026-10-16')],
        ])->assertUnprocessable();

        $this->actingAs($user)->putJson($url, [
            'month' => '2026-10',
            'days' => [$this->day('2026-10-15', null, ['absence_minutes' => 600, 'worked_minutes' => 0])],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('worktime_entries', 0);
    }
    public function test_department_manager_can_write_only_the_employee_in_their_explicit_scope(): void
    {
        config()->set('hrm.worktime_writes_enabled', true);
        [$manager, $company, $employee, $url] = $this->fixture('department_manager');
        $department = (string) Str::uuid();
        $otherDepartment = (string) Str::uuid();
        $position = (string) Str::uuid();
        $otherEmployee = (string) Str::uuid();
        DB::table('departments')->insert([
            ['id' => $department, 'company_id' => $company, 'name' => 'Scoped department'],
            ['id' => $otherDepartment, 'company_id' => $company, 'name' => 'Other department'],
        ]);
        DB::table('positions')->insert(['id' => $position, 'company_id' => $company, 'name' => 'Worker']);
        DB::table('employees')->insert([
            'id' => $otherEmployee, 'company_id' => $company, 'personnel_number' => 'OTHER',
            'last_name' => 'Other', 'first_name' => 'Employee', 'revision' => 1,
        ]);
        foreach ([$employee => $department, $otherEmployee => $otherDepartment] as $id => $dep) {
            DB::table('employee_assignments')->insert([
                'id' => (string) Str::uuid(), 'employee_id' => $id,
                'department_id' => $dep, 'position_id' => $position,
                'valid_from' => '2026-01-01', 'revision' => 1,
            ]);
        }
        DB::table('department_access_scopes')->insert([
            'company_id' => $company, 'user_id' => $manager->id, 'department_id' => $department,
        ]);
        $otherUrl = "/api/v1/companies/{$company}/worktime/timesheet/{$otherEmployee}";
        $payload = ['month' => '2026-10', 'days' => [$this->day()]];

        $this->actingAs($manager)->putJson($url, $payload)->assertForbidden();
        config()->set('hrm.worktime_manager_entry_enabled', true);
        $this->actingAs($manager)->getJson($url.'?month=2026-10')->assertOk();
        $this->actingAs($manager)->putJson($url, $payload)
            ->assertOk()->assertJsonPath('meta.created_count', 1);
        $this->actingAs($manager)->getJson($otherUrl.'?month=2026-10')->assertForbidden();
        $this->actingAs($manager)->putJson($otherUrl, $payload)->assertForbidden();
        $this->assertDatabaseMissing('worktime_entries', [
            'employee_id' => $otherEmployee, 'work_date' => '2026-10-15',
        ]);

        DB::table('department_access_scopes')->where('company_id', $company)->delete();
        $this->actingAs($manager)->putJson($url, [
            'month' => '2026-10', 'days' => [$this->day('2026-10-15', 1)],
        ])->assertForbidden();
        $this->assertDatabaseHas('worktime_entries', [
            'employee_id' => $employee, 'work_date' => '2026-10-15', 'revision' => 1,
        ]);
    }

}
