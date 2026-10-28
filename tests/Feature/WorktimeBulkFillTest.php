<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorktimeBulkFillTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $role = 'company_admin'): array
    {
        $this->travelTo(now()->setDate(2026, 10, 28));
        $user = User::factory()->create();
        $company = (string) Str::uuid();
        $department = (string) Str::uuid();
        $position = (string) Str::uuid();
        $employees = [(string) Str::uuid(), (string) Str::uuid()];
        DB::table('companies')->insert(['id' => $company, 'name' => 'Synthetic bulk company']);
        DB::table('company_user_roles')->insert(['user_id' => $user->id,
            'company_id' => $company, 'role_code' => $role]);
        DB::table('departments')->insert(['id' => $department, 'company_id' => $company,
            'name' => 'Synthetic department']);
        DB::table('positions')->insert(['id' => $position, 'company_id' => $company,
            'name' => 'Synthetic position']);
        foreach ($employees as $index => $id) {
            DB::table('employees')->insert(['id' => $id, 'company_id' => $company,
                'personnel_number' => 'BULK-'.($index + 1), 'first_name' => 'Synthetic', 'last_name' => 'Employee']);
            DB::table('employee_assignments')->insert(['id' => (string) Str::uuid(),
                'employee_id' => $id, 'department_id' => $department, 'position_id' => $position,
                'valid_from' => $index === 0 ? '2026-10-01' : '2026-10-16']);
        }
        foreach (['2026-10-15', '2026-10-16'] as $date) {
            DB::table('production_calendar_days')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $company,
                'work_date' => $date, 'day_type' => 'workday',
                'planned_minutes' => 480, 'revision' => 1,
            ]);
        }
        return [$user, $company, $employees, "/api/v1/companies/{$company}/worktime/timesheet/bulk-fill"];
    }

    public function test_preview_and_apply_fill_only_missing_eligible_days_without_overwriting(): void
    {
        config()->set('hrm.worktime_writes_enabled', true);
        config()->set('hrm.worktime_bulk_fill_enabled', true);
        [$user, $company, $employees, $url] = $this->fixture();
        DB::table('worktime_entries')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $employees[0],
            'work_date' => '2026-10-15', 'worked_minutes' => 240,
            'planned_minutes' => 480, 'revision' => 2,
        ]);
        DB::table('employee_absences')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $employees[0],
            'absence_type' => 'sick', 'reason' => 'Synthetic absence',
            'started_on' => '2026-10-16', 'ended_on' => '2026-10-16', 'status' => 'recorded',
        ]);
        $payload = [
            'month' => '2026-10', 'employee_ids' => $employees,
            'dates' => ['2026-10-15', '2026-10-16'], 'apply' => false,
        ];
        $preview = $this->actingAs($user)->postJson($url, $payload)->assertOk()
            ->assertJsonPath('meta.mode', 'preview')
            ->assertJsonPath('data.candidate_count', 1)
            ->assertJsonPath('data.already_recorded', 1)
            ->assertJsonPath('data.outside_employment', 1)
            ->assertJsonPath('data.recorded_absence', 1)->json();
        $token = $preview['meta']['preview_token'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertDatabaseCount('worktime_entries', 1);
        $this->postJson($url, [...$payload, 'apply' => true])->assertUnprocessable();
        $this->postJson($url, [...$payload, 'apply' => true, 'confirm' => true])
            ->assertUnprocessable();
        $this->postJson($url, [...$payload, 'apply' => true, 'confirm' => true,
            'preview_token' => $token])->assertOk()
            ->assertJsonPath('meta.mode', 'applied')
            ->assertJsonPath('meta.created_count', 1);
        $this->assertDatabaseCount('worktime_entries', 2);
        $this->assertDatabaseHas('worktime_entries', [
            'employee_id' => $employees[0], 'work_date' => '2026-10-15',
            'worked_minutes' => 240, 'revision' => 2,
        ]);
        $this->assertDatabaseHas('worktime_entries', [
            'employee_id' => $employees[1], 'work_date' => '2026-10-16',
            'worked_minutes' => 480, 'revision' => 1,
        ]);
        $this->postJson($url, [...$payload, 'apply' => true, 'confirm' => true,
            'preview_token' => $token])->assertStatus(409);
        $next = $this->postJson($url, $payload)->assertOk()->json('meta.preview_token');
        $this->postJson($url, [...$payload, 'apply' => true, 'confirm' => true,
            'preview_token' => $next])->assertOk()->assertJsonPath('meta.created_count', 0);
        $this->assertDatabaseCount('worktime_entries', 2);
    }

    public function test_endpoint_is_fail_closed_role_scoped_and_rejects_foreign_employees(): void
    {
        [$admin, $company, $employees, $url] = $this->fixture();
        $payload = ['month' => '2026-10', 'employee_ids' => $employees,
            'dates' => ['2026-10-15'], 'apply' => false];
        $this->actingAs($admin)->postJson($url, $payload)->assertNotFound();
        config()->set('hrm.worktime_writes_enabled', true);
        config()->set('hrm.worktime_bulk_fill_enabled', true);
        $other = User::factory()->create();
        $this->actingAs($other)->postJson($url, $payload)->assertForbidden();
        $this->actingAs($admin)->postJson($url, [
            ...$payload, 'employee_ids' => [(string) Str::uuid()],
        ])->assertUnprocessable();
        $this->postJson($url, [
            ...$payload, 'dates' => ['2026-09-15'],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('worktime_entries', 0);
    }

    public function test_future_dates_are_skipped_and_a_changed_preview_is_rejected(): void
    {
        config()->set('hrm.worktime_writes_enabled', true);
        config()->set('hrm.worktime_bulk_fill_enabled', true);
        [$user, $company, $employees, $url] = $this->fixture();
        DB::table('production_calendar_days')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company,
            'work_date' => '2026-10-30', 'day_type' => 'workday',
            'planned_minutes' => 480, 'revision' => 1,
        ]);
        $payload = [
            'month' => '2026-10', 'employee_ids' => $employees,
            'dates' => ['2026-10-15', '2026-10-30'], 'apply' => false,
        ];
        $response = $this->actingAs($user)->postJson($url, $payload)->assertOk()
            ->assertJsonPath('data.future_date', 2)->assertJsonPath('data.candidate_count', 1)->json();
        $token = $response['meta']['preview_token'];
        DB::table('worktime_entries')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $employees[0],
            'work_date' => '2026-10-15', 'planned_minutes' => 480,
            'worked_minutes' => 240, 'revision' => 1,
        ]);
        $this->postJson($url, [...$payload, 'apply' => true,
            'confirm' => true, 'preview_token' => $token])->assertStatus(409);
        $this->assertDatabaseCount('worktime_entries', 1);
        $newToken = $this->postJson($url, $payload)->assertOk()->json('meta.preview_token');
        $this->postJson($url, [...$payload, 'apply' => true,
            'confirm' => true, 'preview_token' => $newToken])->assertStatus(200);
        $this->assertDatabaseCount('worktime_entries', 1);
    }
}
