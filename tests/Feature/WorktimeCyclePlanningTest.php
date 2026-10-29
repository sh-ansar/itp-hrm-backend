<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorktimeCyclePlanningTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
        $admin = User::factory()->create();
        $manager = User::factory()->create();
        $outsider = User::factory()->create();
        $company = (string) Str::uuid();
        $root = (string) Str::uuid();
        $child = (string) Str::uuid();
        $other = (string) Str::uuid();
        $position = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $stranger = (string) Str::uuid();
        $schedule = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $company, 'name' => 'Synthetic planning tenant']);
        DB::table('company_user_roles')->insert([
            ['user_id' => $admin->id, 'company_id' => $company, 'role_code' => 'company_admin'],
            ['user_id' => $manager->id, 'company_id' => $company, 'role_code' => 'department_manager'],
            ['user_id' => $outsider->id, 'company_id' => $company, 'role_code' => 'employee'],
        ]);
        DB::table('departments')->insert([
            ['id' => $root, 'company_id' => $company, 'parent_id' => null, 'name' => 'Main department'],
            ['id' => $child, 'company_id' => $company, 'parent_id' => $root, 'name' => 'Nested team'],
            ['id' => $other, 'company_id' => $company, 'parent_id' => null, 'name' => 'Unrelated team'],
        ]);
        DB::table('positions')->insert(['id' => $position, 'company_id' => $company, 'name' => 'Engineer']);
        DB::table('department_access_scopes')->insert([
            'user_id' => $manager->id, 'company_id' => $company, 'department_id' => $root,
        ]);
        foreach ([[$employee, $child, '001'], [$stranger, $other, '002']] as [$id, $dept, $number]) {
            DB::table('employees')->insert([
                'id' => $id, 'company_id' => $company, 'personnel_number' => 'PLAN-'.$number,
                'last_name' => 'Synthetic', 'first_name' => $number, 'revision' => 1,
            ]);
            DB::table('employee_assignments')->insert([
                'id' => (string) Str::uuid(), 'employee_id' => $id, 'department_id' => $dept,
                'position_id' => $position, 'valid_from' => '2026-01-01',
            ]);
        }
        DB::table('work_schedules')->insert([
            'id' => $schedule, 'company_id' => $company, 'code' => 'SHIFT',
            'name' => 'Two day rotation', 'schedule_type' => 'shift',
            'work_time_label' => '08:00-16:00', 'weekly_minutes' => 2400,
            'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        for ($date = CarbonImmutable::parse('2026-11-01');
            $date->lessThanOrEqualTo(CarbonImmutable::parse('2026-11-30')); $date = $date->addDay()) {
            DB::table('production_calendar_days')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $company,
                'work_date' => $date->toDateString(), 'day_type' => 'workday',
                'planned_minutes' => 480, 'revision' => 1,
            ]);
        }
        return compact('admin', 'manager', 'outsider', 'company',
            'root', 'child', 'other', 'position', 'employee', 'stranger', 'schedule');
    }

    private function enablePlanning(bool $manager = false): void
    {
        config()->set('hrm.worktime_writes_enabled', true);
        config()->set('hrm.worktime_planning_enabled', true);
        config()->set('hrm.worktime_manager_planning_enabled', $manager);
    }

    private function createPattern(array $fx, string $policy = 'cycle', ?array $days = null): void
    {
        $days ??= [
            ['minutes' => 480, 'shift_code' => 'D'],
            ['minutes' => 480, 'shift_code' => 'D'],
            ['minutes' => 0, 'shift_code' => null],
            ['minutes' => 0, 'shift_code' => null],
        ];
        $this->actingAs($fx['admin'])->putJson(
            "/api/v1/companies/{$fx['company']}/worktime/schedules/{$fx['schedule']}/pattern", [
                'revision' => 1, 'calendar_policy' => $policy, 'days' => $days,
            ],
        )->assertOk()->assertJsonPath('data.version', 2);
    }

    private function request(array $fx, array $overrides = []): array
    {
        return array_merge([
            'employee_ids' => [$fx['employee']], 'from' => '2026-11-01',
            'to' => '2026-11-08', 'schedule_revision' => 2,
            'cycle_offset' => 0, 'apply' => false,
        ], $overrides);
    }

    private function url(array $fx): string
    {
        return "/api/v1/companies/{$fx['company']}/worktime/schedules/{$fx['schedule']}/bulk-plan";
    }

    public function test_flags_fail_closed_and_manager_cannot_change_a_company_template(): void
    {
        $fx = $this->fixture();
        $url = "/api/v1/companies/{$fx['company']}/worktime/schedules/{$fx['schedule']}/pattern";
        $this->actingAs($fx['admin'])->getJson($url)->assertNotFound();
        $this->postJson($this->url($fx), $this->request($fx))->assertNotFound();
        $this->enablePlanning(true);
        $this->actingAs($fx['manager'])->putJson($url, [
            'revision' => 1, 'calendar_policy' => 'cycle',
            'days' => [['minutes' => 480, 'shift_code' => 'D']],
        ])->assertForbidden();
        $this->actingAs($fx['outsider'])->getJson($url)->assertForbidden();
        $this->actingAs($fx['admin'])->getJson($url)->assertOk()
            ->assertJsonPath('data', null)->assertJsonPath('meta.schedule_revision', 1);
    }

    public function test_pattern_is_versioned_and_requires_matching_revision(): void
    {
        $fx = $this->fixture();
        $this->enablePlanning();
        $this->createPattern($fx);
        $this->actingAs($fx['admin'])
            ->getJson("/api/v1/companies/{$fx['company']}/worktime/schedules/{$fx['schedule']}/pattern")
            ->assertJsonPath('data.days.0.minutes', 480)
            ->assertJsonPath('meta.schedule_revision', 2);
        $this->actingAs($fx['admin'])->putJson(
            "/api/v1/companies/{$fx['company']}/worktime/schedules/{$fx['schedule']}/pattern", [
                'revision' => 1, 'calendar_policy' => 'cycle',
                'days' => [['minutes' => 420, 'shift_code' => 'D']],
            ],
        )->assertStatus(409);
        $this->assertDatabaseCount('work_schedule_patterns', 1);
    }

    public function test_year_style_cycle_preview_and_apply_write_plans_not_actuals(): void
    {
        $fx = $this->fixture();
        $this->enablePlanning();
        $this->createPattern($fx);
        DB::table('production_calendar_days')->where('company_id', $fx['company'])
            ->where('work_date', '2026-11-01')->update(['day_type' => 'holiday']);
        $payload = $this->request($fx);
        $preview = $this->actingAs($fx['admin'])->postJson($this->url($fx), $payload)
            ->assertOk()->assertJsonPath('data.planned_workdays', 4)
            ->assertJsonPath('data.planned_rest_days', 4)
            ->assertJsonPath('data.planned_minutes', 1920)
            ->assertJsonPath('data.blocking_count', 0)
            ->assertJsonPath('meta.can_apply', true)->json();
        $this->assertDatabaseCount('employee_work_plans', 0);
        $this->postJson($this->url($fx), [...$payload, 'apply' => true])
            ->assertUnprocessable();
        $this->postJson($this->url($fx), [
            ...$payload, 'apply' => true, 'confirm' => true,
            'preview_token' => $preview['meta']['preview_token'],
        ])->assertOk()->assertJsonPath('meta.mode', 'applied');
        $this->assertDatabaseCount('employee_work_plans', 8);
        $this->assertDatabaseCount('worktime_entries', 0);
        $this->assertDatabaseHas('employee_work_plans', [
            'employee_id' => $fx['employee'], 'work_date' => '2026-11-01',
            'planned_minutes' => 480, 'day_kind' => 'work',
        ]);
        $this->assertDatabaseHas('employee_work_plans', [
            'employee_id' => $fx['employee'], 'work_date' => '2026-11-03',
            'planned_minutes' => 0, 'day_kind' => 'rest',
        ]);
    }

    public function test_manager_is_scoped_to_nested_team_but_not_other_department(): void
    {
        $fx = $this->fixture();
        $this->enablePlanning(true);
        $this->createPattern($fx);
        $this->actingAs($fx['manager'])->postJson($this->url($fx), $this->request($fx))
            ->assertOk()->assertJsonPath('data.blocking_count', 0);
        $this->postJson($this->url($fx), $this->request($fx, [
            'employee_ids' => [$fx['employee'], $fx['stranger']],
        ]))->assertForbidden();
        $this->actingAs($fx['outsider'])->postJson($this->url($fx), $this->request($fx))
            ->assertForbidden();

        DB::table('employee_assignments')->where('employee_id', $fx['employee'])
            ->update(['valid_to' => '2026-11-04']);
        DB::table('employee_assignments')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $fx['employee'],
            'department_id' => $fx['other'], 'position_id' => $fx['position'],
            'valid_from' => '2026-11-05',
        ]);
        $this->actingAs($fx['manager'])->postJson($this->url($fx), $this->request($fx))
            ->assertForbidden();
    }

    public function test_approved_period_and_recorded_fact_block_future_rescheduling(): void
    {
        $fx = $this->fixture();
        $this->enablePlanning();
        $this->createPattern($fx);
        DB::table('worktime_periods')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $fx['company'],
            'month' => '2026-11', 'status' => 'approved', 'revision' => 1,
        ]);
        $preview = $this->actingAs($fx['admin'])
            ->postJson($this->url($fx), $this->request($fx))->assertOk()
            ->assertJsonPath('data.locked_days', 8)->json();
        $this->postJson($this->url($fx), [
            ...$this->request($fx), 'apply' => true, 'confirm' => true,
            'preview_token' => $preview['meta']['preview_token'],
        ])->assertStatus(409);
        DB::table('worktime_periods')->delete();
        DB::table('worktime_entries')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $fx['employee'],
            'work_date' => '2026-11-03', 'planned_minutes' => 480,
            'worked_minutes' => 0, 'revision' => 1,
        ]);
        $this->postJson($this->url($fx), $this->request($fx))
            ->assertOk()->assertJsonPath('data.recorded_actual', 1)
            ->assertJsonPath('meta.can_apply', false);
        $this->assertDatabaseCount('employee_work_plans', 0);
    }

    public function test_preview_detects_changed_calendar_and_avoids_partial_writes(): void
    {
        $fx = $this->fixture();
        $this->enablePlanning();
        $this->createPattern($fx);
        $preview = $this->actingAs($fx['admin'])
            ->postJson($this->url($fx), $this->request($fx))->assertOk()->json();
        DB::table('production_calendar_days')->where('company_id', $fx['company'])
            ->where('work_date', '2026-11-04')->update([
                'day_type' => 'shortened', 'planned_minutes' => 420, 'revision' => 2,
            ]);
        $this->postJson($this->url($fx), [
            ...$this->request($fx), 'apply' => true, 'confirm' => true,
            'preview_token' => $preview['meta']['preview_token'],
        ])->assertStatus(409);
        $this->assertDatabaseCount('employee_work_plans', 0);
        $this->assertDatabaseCount('employee_work_schedules', 0);
    }

    public function test_company_calendar_cycle_observes_shortened_days_and_weekends(): void
    {
        $fx = $this->fixture();
        $this->enablePlanning();
        $days = array_map(fn (int $i): array => [
            'minutes' => $i < 5 ? 480 : 0, 'shift_code' => null,
        ], range(0, 6));
        $this->createPattern($fx, 'company', $days);
        DB::table('production_calendar_days')->where('company_id', $fx['company'])
            ->where('work_date', '2026-11-04')
            ->update(['day_type' => 'shortened', 'planned_minutes' => 420]);
        DB::table('production_calendar_days')->where('company_id', $fx['company'])
            ->where('work_date', '2026-11-05')
            ->update(['day_type' => 'holiday', 'planned_minutes' => 0]);
        $preview = $this->actingAs($fx['admin'])
            ->postJson($this->url($fx), $this->request($fx))
            ->assertOk()->json();
        $this->postJson($this->url($fx), [
            ...$this->request($fx), 'apply' => true, 'confirm' => true,
            'preview_token' => $preview['meta']['preview_token'],
        ])->assertOk();
        $this->assertDatabaseHas('employee_work_plans', [
            'employee_id' => $fx['employee'], 'work_date' => '2026-11-01',
            'planned_minutes' => 0,
        ]);
        $this->assertDatabaseHas('employee_work_plans', [
            'employee_id' => $fx['employee'], 'work_date' => '2026-11-04',
            'planned_minutes' => 420,
        ]);
        $this->assertDatabaseHas('employee_work_plans', [
            'employee_id' => $fx['employee'], 'work_date' => '2026-11-05',
            'planned_minutes' => 0,
        ]);
    }

    public function test_six_day_template_keeps_saturday_when_company_default_is_five_day_week(): void
    {
        $fx = $this->fixture();
        $this->enablePlanning();
        $days = array_map(fn (int $i): array => [
            'minutes' => $i < 6 ? 420 : 0, 'shift_code' => null,
        ], range(0, 6));
        $this->createPattern($fx, 'company', $days);
        DB::table('production_calendar_days')->where('company_id', $fx['company'])
            ->where('work_date', '2026-11-07')
            ->update(['day_type' => 'weekend', 'planned_minutes' => 0]);
        $payload = $this->request($fx);
        $preview = $this->actingAs($fx['admin'])->postJson($this->url($fx), $payload)
            ->assertOk()->json('meta.preview_token');
        $this->postJson($this->url($fx), [
            ...$payload, 'apply' => true, 'confirm' => true, 'preview_token' => $preview,
        ])->assertOk();
        $this->assertDatabaseHas('employee_work_plans', [
            'employee_id' => $fx['employee'],
            'work_date' => '2026-11-07', 'planned_minutes' => 420,
        ]);
    }

    public function test_future_replacement_splits_assignment_but_preserves_outside_days(): void
    {
        $fx = $this->fixture();
        $this->enablePlanning();
        $this->createPattern($fx);
        $first = $this->request($fx, ['to' => '2026-11-10']);
        $initial = $this->actingAs($fx['admin'])->postJson($this->url($fx), $first)
            ->assertOk()->json('meta.preview_token');
        $this->postJson($this->url($fx), [
            ...$first, 'apply' => true, 'confirm' => true, 'preview_token' => $initial,
        ])->assertOk();
        $middle = $this->request($fx, [
            'from' => '2026-11-04', 'to' => '2026-11-06', 'cycle_offset' => 1,
            'replace_future_plans' => true,
        ]);
        $preview = $this->postJson($this->url($fx), $middle)
            ->assertOk()->assertJsonPath('data.existing_plans', 3)
            ->assertJsonPath('data.blocking_count', 0)->json();
        $this->postJson($this->url($fx), [
            ...$middle, 'apply' => true, 'confirm' => true,
            'preview_token' => $preview['meta']['preview_token'],
        ])->assertOk();
        $this->assertDatabaseCount('employee_work_plans', 10);
        $this->assertDatabaseCount('employee_work_schedules', 3);
        $this->assertDatabaseHas('employee_work_plans', [
            'employee_id' => $fx['employee'], 'work_date' => '2026-11-04',
            'planned_minutes' => 480, 'revision' => 2,
        ]);
        $intervals = DB::table('employee_work_schedules')
            ->where('employee_id', $fx['employee'])->orderBy('valid_from')->get();
        $this->assertSame(['2026-11-01', '2026-11-04', '2026-11-07'],
            $intervals->pluck('valid_from')->all());
        $this->assertSame(['2026-11-03', '2026-11-06', '2026-11-10'],
            $intervals->pluck('valid_to')->all());
    }

    public function test_previously_approved_leave_blocks_mass_planning(): void
    {
        $fx = $this->fixture();
        $this->enablePlanning();
        $this->createPattern($fx);
        $type = (string) Str::uuid();
        DB::table('leave_types')->insert([
            'id' => $type, 'company_id' => $fx['company'], 'code' => 'ANNUAL',
            'name' => 'Synthetic annual', 'category' => 'annual',
            'annual_days' => 24, 'balance_method' => 'calendar', 'revision' => 1,
        ]);
        DB::table('employee_leaves')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $fx['employee'],
            'leave_type_id' => $type, 'planned_started_on' => '2026-11-02',
            'planned_ended_on' => '2026-11-03', 'planned_days' => 2,
            'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $payload = $this->request($fx);
        $preview = $this->actingAs($fx['admin'])->postJson($this->url($fx), $payload)
            ->assertOk()->assertJsonPath('data.overlapping_leave', 2)
            ->assertJsonPath('meta.can_apply', false)->json();
        $this->postJson($this->url($fx), [
            ...$payload, 'apply' => true, 'confirm' => true,
            'preview_token' => $preview['meta']['preview_token'],
        ])->assertStatus(409);
        $this->assertDatabaseCount('employee_work_plans', 0);
    }

    public function test_manager_reads_only_own_nested_team_plans(): void
    {
        $fx = $this->fixture();
        $this->enablePlanning(true);
        $this->createPattern($fx);
        $payload = $this->request($fx);
        $token = $this->actingAs($fx['admin'])->postJson($this->url($fx), $payload)
            ->assertOk()->json('meta.preview_token');
        $this->postJson($this->url($fx), [
            ...$payload, 'apply' => true, 'confirm' => true, 'preview_token' => $token,
        ])->assertOk();

        $readUrl = "/api/v1/companies/{$fx['company']}/worktime/plans";
        $this->actingAs($fx['manager'])->getJson($readUrl.'?'.http_build_query([
            'from' => '2026-11-01', 'to' => '2026-11-08',
            'employee_ids' => [$fx['employee']],
        ]))->assertOk()->assertJsonPath('meta.total', 8)
            ->assertJsonPath('data.0.employee_id', $fx['employee']);
        $this->getJson($readUrl.'?'.http_build_query([
            'from' => '2026-11-01', 'to' => '2026-11-08',
            'employee_ids' => [$fx['employee'], $fx['stranger']],
        ]))->assertNotFound();
        DB::table('employee_assignments')->where('employee_id', $fx['employee'])
            ->update(['valid_to' => '2026-11-04']);
        DB::table('employee_assignments')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $fx['employee'],
            'department_id' => $fx['other'], 'position_id' => $fx['position'],
            'valid_from' => '2026-11-05',
        ]);
        $this->getJson($readUrl.'?'.http_build_query([
            'from' => '2026-11-01', 'to' => '2026-11-08',
            'employee_ids' => [$fx['employee']],
        ]))->assertNotFound();
        $this->actingAs($fx['outsider'])->getJson($readUrl.'?'.http_build_query([
            'from' => '2026-11-01', 'to' => '2026-11-08',
            'employee_ids' => [$fx['employee']],
        ]))->assertForbidden();
    }

    public function test_verified_shift_plans_drive_bulk_actuals_without_faking_future_days(): void
    {
        $fx = $this->fixture();
        $this->enablePlanning();
        $this->createPattern($fx);
        DB::table('production_calendar_days')->where('company_id', $fx['company'])
            ->where('work_date', '2026-11-01')->update(['day_type' => 'holiday']);
        $payload = $this->request($fx);
        $token = $this->actingAs($fx['admin'])->postJson($this->url($fx), $payload)
            ->assertOk()->json('meta.preview_token');
        $this->postJson($this->url($fx), [
            ...$payload, 'apply' => true, 'confirm' => true, 'preview_token' => $token,
        ])->assertOk();
        config()->set('hrm.worktime_bulk_fill_enabled', true);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00'));
        $bulk = "/api/v1/companies/{$fx['company']}/worktime/timesheet/bulk-fill";
        $request = ['month' => '2026-11', 'employee_ids' => [$fx['employee']],
            'dates' => ['2026-11-01', '2026-11-03'], 'apply' => false];
        $response = $this->actingAs($fx['admin'])->postJson($bulk, $request)
            ->assertOk()->assertJsonPath('data.candidate_count', 1)
            ->assertJsonPath('data.nonworking_date', 1)
            ->assertJsonPath('data.unknown_shift_schedule', 0)->json();
        $this->postJson($bulk, [...$request, 'apply' => true,
            'confirm' => true, 'preview_token' => $response['meta']['preview_token']])
            ->assertOk()->assertJsonPath('meta.created_count', 1);
        $this->assertDatabaseHas('worktime_entries', [
            'employee_id' => $fx['employee'], 'work_date' => '2026-11-01',
            'planned_minutes' => 480, 'worked_minutes' => 480,
        ]);
        $this->assertDatabaseMissing('worktime_entries', [
            'employee_id' => $fx['employee'], 'work_date' => '2026-11-03',
        ]);
        $this->actingAs($fx['admin'])->getJson(
            "/api/v1/companies/{$fx['company']}/worktime/timesheet/{$fx['employee']}?month=2026-11"
        )->assertOk()->assertJsonPath('data.0.planned_minutes', 480)
            ->assertJsonPath('data.2.planned_minutes', 0);
    }
}