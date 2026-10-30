<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorktimeEmploymentPeriodGuardTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00'));
        config()->set([
            'hrm.worktime_writes_enabled' => true,
            'hrm.worktime_matrix_enabled' => true,
            'hrm.worktime_employment_guard_enabled' => true,
        ]);
        $company = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $department = (string) Str::uuid();
        $position = (string) Str::uuid();
        $admin = User::factory()->create();

        DB::table('companies')->insert(['id' => $company, 'name' => 'Employment QA']);
        DB::table('employees')->insert([
            'id' => $employee, 'company_id' => $company, 'personnel_number' => 'E-TC-01',
            'last_name' => 'Work', 'first_name' => 'Cycle', 'revision' => 1,
        ]);
        DB::table('departments')->insert(['id' => $department, 'company_id' => $company, 'name' => 'Engineering']);
        DB::table('positions')->insert(['id' => $position, 'company_id' => $company, 'name' => 'Engineer']);
        DB::table('company_user_roles')->insert([
            'company_id' => $company, 'user_id' => $admin->id, 'role_code' => 'company_admin',
        ]);
        foreach ([['2026-10-05', '2026-10-11'], ['2026-10-12', '2026-10-20']] as [$start, $end]) {
            DB::table('employee_assignments')->insert([
                'id' => (string) Str::uuid(), 'employee_id' => $employee,
                'department_id' => $department, 'position_id' => $position,
                'valid_from' => $start, 'valid_to' => $end, 'revision' => 2,
            ]);
        }
        foreach (['2026-10-02', '2026-10-07', '2026-10-11', '2026-10-12',
            '2026-10-20', '2026-10-21'] as $day) {
            DB::table('production_calendar_days')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $company,
                'work_date' => $day, 'day_type' => 'workday',
                'planned_minutes' => 480, 'revision' => 1,
            ]);
        }

        return compact('admin', 'company', 'employee');
    }

    private function cell(string $date, ?int $revision = null, ?int $worked = 480): array
    {
        return [
            'date' => $date, 'revision' => $revision,
            'worked_minutes' => $worked,
            'absence_minutes' => $worked === null ? null : 0,
            'overtime_minutes' => $worked === null ? null : 0,
        ];
    }

    public function test_valid_hire_transfer_termination_boundaries_and_no_actual_outside_employment(): void
    {
        extract($this->fixture());
        $url = "/api/v1/companies/{$company}/worktime/timesheet/{$employee}";
        $this->actingAs($admin);

        $this->putJson($url, ['month' => '2026-10',
            'days' => [$this->cell('2026-10-07'), $this->cell('2026-10-21')],
        ])->assertUnprocessable()->assertJsonValidationErrors(['days.1.date']);
        $this->assertDatabaseCount('worktime_entries', 0);

        foreach (['2026-10-02', '2026-10-21'] as $outside) {
            $this->putJson($url, ['month' => '2026-10',
                'days' => [$this->cell($outside)],
            ])->assertUnprocessable()->assertJsonValidationErrors(['days.0.date']);
            $this->putJson($url, ['month' => '2026-10',
                'days' => [$this->cell($outside, null, null)],
            ])->assertUnprocessable()->assertJsonValidationErrors(['days.0.date']);
        }

        $this->putJson($url, ['month' => '2026-10',
            'days' => [$this->cell('2026-10-11'), $this->cell('2026-10-12'),
                $this->cell('2026-10-20')],
        ])->assertOk()->assertJsonPath('meta.created_count', 3);

        $this->assertDatabaseCount('worktime_entries', 3);
        $response = $this->getJson($url.'?month=2026-10')->assertOk();
        $days = collect($response->json('data'))->keyBy('date');
        foreach (['2026-10-02', '2026-10-21'] as $day) {
            $this->assertSame('outside', $days[$day]['employment_state']);
            $this->assertSame(0, $days[$day]['planned_minutes']);
            $this->assertNull($days[$day]['worked_minutes']);
        }
        foreach (['2026-10-11', '2026-10-12', '2026-10-20'] as $day) {
            $this->assertSame('employed', $days[$day]['employment_state']);
            $this->assertSame(480, $days[$day]['planned_minutes']);
            $this->assertSame(480, $days[$day]['worked_minutes']);
        }
    }

    public function test_existing_legacy_error_can_be_cleared_and_matrix_is_not_editable_outside_employment(): void
    {
        extract($this->fixture());
        $url = "/api/v1/companies/{$company}/worktime/timesheet/{$employee}";
        DB::table('worktime_entries')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $employee,
            'work_date' => '2026-10-21', 'planned_minutes' => 480,
            'worked_minutes' => 480, 'absence_minutes' => 0, 'overtime_minutes' => 0,
            'revision' => 1,
        ]);
        $this->actingAs($admin)->putJson($url, ['month' => '2026-10',
            'days' => [$this->cell('2026-10-21', 1, 420)],
        ])->assertUnprocessable()->assertJsonValidationErrors('days.0.date');
        $this->assertDatabaseHas('worktime_entries', [
            'employee_id' => $employee, 'work_date' => '2026-10-21', 'worked_minutes' => 480,
        ]);

        $this->putJson($url, ['month' => '2026-10',
            'days' => [$this->cell('2026-10-21', 1, null)],
        ])->assertOk()->assertJsonPath('meta.updated_count', 1);
        $this->assertDatabaseHas('worktime_entries', [
            'employee_id' => $employee, 'work_date' => '2026-10-21',
            'worked_minutes' => null, 'revision' => 2,
        ]);

        $matrix = $this->getJson("/api/v1/companies/{$company}/worktime/matrix?"
            .http_build_query(['from' => '2026-10-20', 'to' => '2026-10-21']))
            ->assertOk()->assertJsonPath('meta.total', 1);
        $days = collect($matrix->json('data.rows.0.days'))->keyBy('date');
        $this->assertSame('outside_employment', $days['2026-10-21']['state']);
        $this->assertSame(0, $days['2026-10-21']['planned_minutes']);
        $this->assertFalse($days['2026-10-21']['editable']);
        $this->assertSame('missing', $days['2026-10-20']['state']);
        $this->assertTrue($days['2026-10-20']['editable']);
        $this->assertSame(480, $days['2026-10-20']['planned_minutes']);
        $this->assertSame(480, DB::table('worktime_entries')->where('employee_id', $employee)
            ->where('work_date', '2026-10-21')->value('planned_minutes'),
            'Historical stored planned minutes are kept for audit and not rewritten');
    }
}
