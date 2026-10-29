<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorktimeMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 14:00:00'));
        config()->set('hrm.worktime_matrix_enabled', true);
        config()->set('hrm.worktime_writes_enabled', true);
        config()->set('hrm.worktime_manager_entry_enabled', false);
        $admin = User::factory()->create();
        $manager = User::factory()->create();
        $outsider = User::factory()->create();
        $company = (string) Str::uuid();
        $otherCompany = (string) Str::uuid();
        $main = (string) Str::uuid();
        $nested = (string) Str::uuid();
        $other = (string) Str::uuid();
        $position = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $stranger = (string) Str::uuid();
        DB::table('companies')->insert([
            ['id' => $company, 'name' => 'Matrix Synthetic Tenant'],
            ['id' => $otherCompany, 'name' => 'Other Tenant'],
        ]);
        DB::table('company_user_roles')->insert([
            ['company_id' => $company, 'user_id' => $admin->id, 'role_code' => 'company_admin'],
            ['company_id' => $company, 'user_id' => $manager->id, 'role_code' => 'department_manager'],
            ['company_id' => $company, 'user_id' => $outsider->id, 'role_code' => 'employee'],
        ]);
        DB::table('departments')->insert([
            ['id' => $main, 'company_id' => $company, 'parent_id' => null, 'name' => 'IT'],
            ['id' => $nested, 'company_id' => $company, 'parent_id' => $main, 'name' => 'Developers'],
            ['id' => $other, 'company_id' => $company, 'parent_id' => null, 'name' => 'Finance'],
        ]);
        DB::table('positions')->insert(['id' => $position, 'company_id' => $company, 'name' => 'Engineer']);
        DB::table('department_access_scopes')->insert([
            'company_id' => $company, 'department_id' => $main, 'user_id' => $manager->id,
        ]);
        foreach ([[$employee, $nested, '001', 'Ivanov'], [$stranger, $other, '002', 'Petrov']]
            as [$id, $dept, $number, $family]) {
            DB::table('employees')->insert([
                'id' => $id, 'company_id' => $company,
                'personnel_number' => 'MTR-'.$number, 'last_name' => $family,
                'first_name' => 'Synthetic', 'revision' => 1,
            ]);
            DB::table('employee_assignments')->insert([
                'id' => (string) Str::uuid(), 'employee_id' => $id,
                'department_id' => $dept, 'position_id' => $position,
                'valid_from' => '2026-01-01',
            ]);
        }
        for ($d = CarbonImmutable::parse('2026-10-20');
            $d->lessThanOrEqualTo(CarbonImmutable::parse('2026-11-09')); $d = $d->addDay()) {
            $weekend = $d->dayOfWeekIso >= 6;
            DB::table('production_calendar_days')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $company,
                'work_date' => $d->toDateString(),
                'day_type' => $weekend ? 'weekend' : 'workday',
                'planned_minutes' => $weekend ? 0 : 480, 'revision' => 1,
            ]);
        }
        return compact('admin', 'manager', 'outsider', 'company', 'otherCompany',
            'main', 'nested', 'other', 'position', 'employee', 'stranger');
    }

    private function matrix(array $fx, string $from = '2026-10-29', string $to = '2026-11-03', array $params = []): string
    {
        return "/api/v1/companies/{$fx['company']}/worktime/matrix?" .
            http_build_query(['from' => $from, 'to' => $to, ...$params]);
    }

    private function bulk(array $fx): string
    {
        return "/api/v1/companies/{$fx['company']}/worktime/matrix/bulk-edit";
    }

    private function payload(array $fx, array $overrides = []): array
    {
        return [
            'employee_ids' => [$fx['employee']],
            'dates' => ['2026-10-29', '2026-10-30', '2026-11-02', '2026-11-03'],
            'operation' => 'fill_missing', 'apply' => false,
            ...$overrides,
        ];
    }

    public function test_flags_and_roles_deny_non_staff_and_cross_company(): void
    {
        $fx = $this->fixture();
        config()->set('hrm.worktime_matrix_enabled', false);
        $this->actingAs($fx['admin'])->getJson($this->matrix($fx))->assertNotFound();
        $this->postJson($this->bulk($fx), $this->payload($fx))->assertNotFound();
        config()->set('hrm.worktime_matrix_enabled', true);
        $this->actingAs($fx['outsider'])->getJson($this->matrix($fx))->assertForbidden();
        $this->postJson($this->bulk($fx), $this->payload($fx))->assertForbidden();
        $this->actingAs($fx['admin'])->getJson(
            "/api/v1/companies/{$fx['otherCompany']}/worktime/matrix?from=2026-10-29&to=2026-11-03",
        )->assertForbidden();
    }

    public function test_matrix_has_actual_planned_days_grouping_server_filters_and_pagination(): void
    {
        $fx = $this->fixture();
        DB::table('worktime_entries')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $fx['employee'],
            'work_date' => '2026-10-29', 'planned_minutes' => 480,
            'worked_minutes' => 450, 'absence_minutes' => 0, 'overtime_minutes' => 0,
            'revision' => 1,
        ]);
        $url = $this->matrix($fx);
        $this->actingAs($fx['admin'])->getJson($url)->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.dates.0.date', '2026-10-29')
            ->assertJsonPath('data.rows.0.department_name', 'Developers')
            ->assertJsonPath('data.rows.0.days.0.worked_minutes', 450)
            ->assertJsonPath('data.rows.0.days.0.state', 'recorded')
            ->assertJsonPath('data.rows.0.days.5.state', 'missing')
            ->assertJsonPath('meta.can_edit', true);
        $this->getJson($this->matrix($fx, '2026-10-29', '2026-11-03', [
            'q' => 'MTR-002', 'group_by' => 'none',
        ]))->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.rows.0.employee_id', $fx['stranger']);
        $this->getJson($this->matrix($fx, '2026-10-29', '2026-11-03', [
            'department_id' => $fx['nested'], 'status' => 'recorded',
        ]))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->matrix($fx, '2026-10-29', '2026-11-03', [
            'per_page' => 1, 'page' => 2,
        ]))->assertOk()->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 2)->assertJsonPath('data.rows.0.employee_id', $fx['stranger']);
    }

    public function test_manager_sees_team_matrix_but_cannot_post_actual_hours_even_with_legacy_flag(): void
    {
        $fx = $this->fixture();
        $this->actingAs($fx['manager'])->getJson($this->matrix($fx))
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.rows.0.employee_id', $fx['employee'])
            ->assertJsonPath('meta.can_edit', false);
        $this->postJson($this->bulk($fx), $this->payload($fx))->assertForbidden();
        config()->set('hrm.worktime_manager_entry_enabled', true);
        $this->getJson($this->matrix($fx))->assertOk()
            ->assertJsonPath('meta.can_edit', false);
        $this->postJson($this->bulk($fx), $this->payload($fx))->assertForbidden();
        $this->postJson($this->bulk($fx), $this->payload($fx, [
            'employee_ids' => [$fx['employee'], $fx['stranger']],
        ]))->assertForbidden();
    }

    public function test_manager_historical_visibility_fails_closed_for_transfer_midrange(): void
    {
        $fx = $this->fixture();
        DB::table('employee_assignments')->where('employee_id', $fx['employee'])
            ->update(['valid_to' => '2026-10-31']);
        DB::table('employee_assignments')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $fx['employee'],
            'department_id' => $fx['other'], 'position_id' => $fx['position'],
            'valid_from' => '2026-11-01',
        ]);
        $this->actingAs($fx['manager'])->getJson($this->matrix($fx))
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->matrix($fx, '2026-10-29', '2026-10-30'))
            ->assertOk()->assertJsonPath('meta.total', 1);
        config()->set('hrm.worktime_manager_entry_enabled', true);
        $this->postJson($this->bulk($fx), $this->payload($fx))->assertForbidden();
    }

    public function test_preview_and_apply_across_months_are_atomic_with_real_actuals_only(): void
    {
        $fx = $this->fixture();
        $payload = $this->payload($fx);
        $preview = $this->actingAs($fx['admin'])->postJson($this->bulk($fx), $payload)
            ->assertOk()->assertJsonPath('data.candidate_count', 4)
            ->assertJsonPath('meta.can_apply', true)->json('meta.preview_token');
        $this->assertDatabaseCount('worktime_entries', 0);
        $this->postJson($this->bulk($fx), [
            ...$payload, 'apply' => true, 'confirm' => true,
            'preview_token' => str_repeat('b', 64),
        ])->assertStatus(409);
        $this->assertDatabaseCount('worktime_entries', 0);
        $this->postJson($this->bulk($fx), [
            ...$payload, 'apply' => true, 'confirm' => true, 'preview_token' => $preview,
        ])->assertOk()->assertJsonPath('data.new_entries', 4)
            ->assertJsonPath('meta.mode', 'applied');
        $this->assertDatabaseCount('worktime_entries', 4);
        $this->assertDatabaseHas('worktime_entries', [
            'employee_id' => $fx['employee'], 'work_date' => '2026-11-02',
            'worked_minutes' => 480, 'planned_minutes' => 480,
        ]);
    }

    public function test_existing_actuals_require_explicit_overwrite_and_stale_preview_blocks(): void
    {
        $fx = $this->fixture();
        $id = (string) Str::uuid();
        DB::table('worktime_entries')->insert([
            'id' => $id, 'employee_id' => $fx['employee'],
            'work_date' => '2026-10-29', 'planned_minutes' => 480,
            'worked_minutes' => 450, 'absence_minutes' => 0, 'overtime_minutes' => 0,
            'revision' => 1,
        ]);
        $this->actingAs($fx['admin']);
        $request = $this->payload($fx, [
            'dates' => ['2026-10-29'], 'operation' => 'set_worked', 'worked_minutes' => 420,
        ]);
        $this->postJson($this->bulk($fx), $request)->assertOk()
            ->assertJsonPath('data.existing_requires_overwrite', 1)
            ->assertJsonPath('meta.can_apply', false);
        $changed = [...$request, 'overwrite_existing' => true];
        $preview = $this->postJson($this->bulk($fx), $changed)->assertOk()
            ->assertJsonPath('data.updates', 1)->json('meta.preview_token');
        DB::table('worktime_entries')->where('id', $id)->update([
            'worked_minutes' => 460, 'revision' => 2,
        ]);
        $this->postJson($this->bulk($fx), [
            ...$changed, 'apply' => true, 'confirm' => true, 'preview_token' => $preview,
        ])->assertStatus(409);
        $this->assertDatabaseHas('worktime_entries', ['id' => $id, 'worked_minutes' => 460]);
        $fresh = $this->postJson($this->bulk($fx), $changed)
            ->assertOk()->json('meta.preview_token');
        $this->postJson($this->bulk($fx), [
            ...$changed, 'apply' => true, 'confirm' => true, 'preview_token' => $fresh,
        ])->assertOk()->assertJsonPath('data.updates', 1);
        $this->assertDatabaseHas('worktime_entries', [
            'id' => $id, 'worked_minutes' => 420, 'revision' => 3,
        ]);
    }

    public function test_approved_period_blocks_entire_cross_month_selection_without_partial_commit(): void
    {
        $fx = $this->fixture();
        DB::table('worktime_periods')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $fx['company'],
            'month' => '2026-11', 'status' => 'approved', 'revision' => 1,
        ]);
        $payload = $this->payload($fx);
        $preview = $this->actingAs($fx['admin'])->postJson($this->bulk($fx), $payload)
            ->assertOk()->assertJsonPath('data.locked_cells', 2)
            ->assertJsonPath('meta.can_apply', false)->json('meta.preview_token');
        $this->postJson($this->bulk($fx), [
            ...$payload, 'apply' => true, 'confirm' => true, 'preview_token' => $preview,
        ])->assertStatus(409);
        $this->assertDatabaseCount('worktime_entries', 0);
    }

    public function test_leave_and_absence_are_skipped_without_touching_protected_records(): void
    {
        $fx = $this->fixture();
        $type = (string) Str::uuid();
        DB::table('leave_types')->insert([
            'id' => $type, 'company_id' => $fx['company'], 'code' => 'ANNUAL',
            'name' => 'Synthetic annual', 'category' => 'annual',
            'annual_days' => 24, 'balance_method' => 'calendar', 'revision' => 1,
        ]);
        DB::table('employee_leaves')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $fx['employee'],
            'leave_type_id' => $type, 'planned_started_on' => '2026-10-29',
            'planned_ended_on' => '2026-10-29', 'planned_days' => 1, 'revision' => 1,
        ]);
        DB::table('employee_absences')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $fx['employee'],
            'absence_type' => 'other', 'reason' => 'test', 'started_on' => '2026-10-30',
            'ended_on' => '2026-10-30', 'status' => 'recorded', 'revision' => 1,
        ]);
        $payload = $this->payload($fx);
        $preview = $this->actingAs($fx['admin'])->postJson($this->bulk($fx), $payload)
            ->assertOk()->assertJsonPath('data.skipped_leave', 1)
            ->assertJsonPath('data.skipped_absence', 1)
            ->assertJsonPath('data.candidate_count', 2)->json('meta.preview_token');
        $this->postJson($this->bulk($fx), [
            ...$payload, 'apply' => true, 'confirm' => true, 'preview_token' => $preview,
        ])->assertOk();
        $this->assertDatabaseCount('worktime_entries', 2);
        $this->assertDatabaseMissing('worktime_entries', [
            'employee_id' => $fx['employee'], 'work_date' => '2026-10-29',
        ]);
    }

    public function test_sparse_cell_selection_does_not_expand_to_cartesian_product(): void
    {
        $fx = $this->fixture();
        $cells = [
            ['employee_id' => $fx['employee'], 'date' => '2026-10-29'],
            ['employee_id' => $fx['stranger'], 'date' => '2026-10-30'],
        ];
        $request = ['cells' => $cells, 'operation' => 'set_worked',
            'worked_minutes' => 420, 'apply' => false];
        $preview = $this->actingAs($fx['admin'])->postJson($this->bulk($fx), $request)
            ->assertOk()->assertJsonPath('data.candidate_count', 2)
            ->json('meta.preview_token');
        $this->postJson($this->bulk($fx), [
            ...$request, 'apply' => true, 'confirm' => true, 'preview_token' => $preview,
        ])->assertOk();
        $this->assertDatabaseCount('worktime_entries', 2);
        $this->assertDatabaseHas('worktime_entries', [
            'employee_id' => $fx['employee'], 'work_date' => '2026-10-29', 'worked_minutes' => 420,
        ]);
        $this->assertDatabaseHas('worktime_entries', [
            'employee_id' => $fx['stranger'], 'work_date' => '2026-10-30', 'worked_minutes' => 420,
        ]);
        $this->assertDatabaseMissing('worktime_entries', [
            'employee_id' => $fx['employee'], 'work_date' => '2026-10-30',
        ]);
        $duplicate = ['cells' => [$cells[0], $cells[0]], 'operation' => 'fill_missing', 'apply' => false];
        $this->postJson($this->bulk($fx), $duplicate)->assertUnprocessable();
    }

    public function test_verified_rotating_shift_overrides_weekend_and_rest_day(): void
    {
        $fx = $this->fixture();
        $schedule = (string) Str::uuid();
        $assignment = (string) Str::uuid();
        $pattern = (string) Str::uuid();
        DB::table('work_schedules')->insert([
            'id' => $schedule, 'company_id' => $fx['company'], 'name' => 'Shift rotation',
            'code' => 'N', 'schedule_type' => 'shift', 'revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('work_schedule_patterns')->insert([
            'id' => $pattern, 'company_id' => $fx['company'], 'work_schedule_id' => $schedule,
            'version' => 1, 'calendar_policy' => 'cycle',
            'days' => json_encode([['minutes' => 480, 'shift_code' => 'N']], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('employee_work_schedules')->insert([
            'id' => $assignment, 'employee_id' => $fx['employee'],
            'work_schedule_id' => $schedule, 'pattern_id' => $pattern,
            'valid_from' => '2026-01-01', 'revision' => 1,
            'cycle_offset' => 0, 'cycle_anchor_on' => '2026-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([['2026-10-30', 0, 'rest'], ['2026-11-01', 480, 'work']]
            as [$date, $minutes, $kind]) {
            DB::table('employee_work_plans')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $fx['company'],
                'employee_id' => $fx['employee'], 'schedule_assignment_id' => $assignment,
                'pattern_id' => $pattern, 'work_date' => $date,
                'planned_minutes' => $minutes, 'day_kind' => $kind, 'source' => 'cycle',
                'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->actingAs($fx['admin'])->getJson($this->matrix($fx, '2026-10-30', '2026-11-01', [
            'q' => 'MTR-001',
        ]))->assertOk()->assertJsonPath('data.rows.0.days.0.state', 'rest')
            ->assertJsonPath('data.rows.0.days.0.planned_minutes', 0)
            ->assertJsonPath('data.rows.0.days.2.planned_minutes', 480);
        $request = $this->payload($fx, ['dates' => ['2026-10-30', '2026-11-01']]);
        $preview = $this->postJson($this->bulk($fx), $request)->assertOk()
            ->assertJsonPath('data.candidate_count', 1)
            ->assertJsonPath('data.skipped_nonworking', 1)->json('meta.preview_token');
        $this->postJson($this->bulk($fx), [
            ...$request, 'apply' => true, 'confirm' => true, 'preview_token' => $preview,
        ])->assertOk();
        $this->assertDatabaseHas('worktime_entries', [
            'employee_id' => $fx['employee'], 'work_date' => '2026-11-01',
            'planned_minutes' => 480, 'worked_minutes' => 480,
        ]);
        $this->assertDatabaseMissing('worktime_entries', [
            'employee_id' => $fx['employee'], 'work_date' => '2026-10-30',
        ]);
    }

    public function test_missing_calendar_blocks_bulk_without_partial_writes(): void
    {
        $fx = $this->fixture();
        DB::table('production_calendar_days')->where('company_id', $fx['company'])
            ->where('work_date', '2026-10-30')->delete();
        $payload = $this->payload($fx);
        $token = $this->actingAs($fx['admin'])->postJson($this->bulk($fx), $payload)
            ->assertOk()->assertJsonPath('data.missing_calendar', 1)
            ->assertJsonPath('meta.can_apply', false)->json('meta.preview_token');
        $this->postJson($this->bulk($fx), [
            ...$payload, 'apply' => true, 'confirm' => true, 'preview_token' => $token,
        ])->assertStatus(409);
        $this->assertDatabaseCount('worktime_entries', 0);
    }

    public function test_future_date_never_creates_actual_and_validation_rejects_large_ranges(): void
    {
        $fx = $this->fixture();
        $request = $this->payload($fx, [
            'dates' => ['2026-11-04', '2026-11-05', '2026-11-06'],
        ]);
        $preview = $this->actingAs($fx['admin'])->postJson($this->bulk($fx), $request)
            ->assertOk()->assertJsonPath('data.skipped_future', 2)
            ->assertJsonPath('data.candidate_count', 1)->json('meta.preview_token');
        $this->postJson($this->bulk($fx), [
            ...$request, 'apply' => true, 'confirm' => true, 'preview_token' => $preview,
        ])->assertOk();
        $this->assertDatabaseCount('worktime_entries', 1);
        $this->assertDatabaseMissing('worktime_entries', [
            'employee_id' => $fx['employee'], 'work_date' => '2026-11-05',
        ]);
        $this->getJson($this->matrix($fx, '2026-09-01', '2026-11-03'))
            ->assertUnprocessable();
        $this->postJson($this->bulk($fx), $this->payload($fx, [
            'dates' => ['2026-01-01', '2026-11-03'],
        ]))->assertUnprocessable();
    }
}
