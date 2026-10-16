<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkScheduleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $role = 'company_admin'): array
    {
        $user = User::factory()->create();
        $company = (string) Str::uuid();
        $employee = (string) Str::uuid();

        DB::table('companies')->insert(['id' => $company, 'name' => 'Synthetic Company']);
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id, 'company_id' => $company, 'role_code' => $role,
        ]);
        DB::table('employees')->insert([
            'id' => $employee, 'company_id' => $company, 'personnel_number' => 'SCH-001',
            'last_name' => 'Schedule', 'first_name' => 'Employee', 'revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $company, $employee];
    }

    private function schedulePayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'STD-5X2',
            'name' => 'Стандарт 5/2',
            'schedule_type' => 'standard',
            'work_time_label' => '09:00–18:00',
            'weekly_minutes' => 2400,
        ], $overrides);
    }

    public function test_schedule_writes_are_fail_closed(): void
    {
        [$user, $company] = $this->fixture();

        $this->actingAs($user)
            ->postJson("/api/v1/companies/{$company}/worktime/schedules", $this->schedulePayload())
            ->assertNotFound();

        $this->assertDatabaseCount('work_schedules', 0);
    }

    public function test_admin_and_hr_can_create_schedules_and_employee_cannot(): void
    {
        config()->set('hrm.worktime_writes_enabled', true);

        foreach (['company_admin', 'hr_specialist'] as $role) {
            [$user, $company] = $this->fixture($role);
            $this->actingAs($user)
                ->postJson("/api/v1/companies/{$company}/worktime/schedules", $this->schedulePayload([
                    'code' => strtoupper($role).'-1',
                ]))
                ->assertCreated()
                ->assertJsonPath('data.revision', 1);
        }

        [$employeeUser, $employeeCompany] = $this->fixture('employee');
        $this->actingAs($employeeUser)
            ->postJson("/api/v1/companies/{$employeeCompany}/worktime/schedules", $this->schedulePayload())
            ->assertForbidden();
    }

    public function test_editor_list_includes_unassigned_schedule(): void
    {
        config()->set('hrm.worktime_writes_enabled', true);
        [$user, $company] = $this->fixture();

        $created = $this->actingAs($user)
            ->postJson("/api/v1/companies/{$company}/worktime/schedules", $this->schedulePayload())
            ->assertCreated()->json('data.id');

        $this->actingAs($user)
            ->getJson("/api/v1/companies/{$company}/worktime/schedules?as_of=2026-10-16")
            ->assertOk()
            ->assertJsonPath('meta.can_manage_schedules', true)
            ->assertJsonFragment([
                'id' => $created,
                'code' => 'STD-5X2',
                'employees' => 0,
                'revision' => 1,
            ]);
    }

    public function test_schedule_update_requires_current_revision_and_audits(): void
    {
        config()->set('hrm.worktime_writes_enabled', true);
        [$user, $company] = $this->fixture();

        $schedule = $this->actingAs($user)
            ->postJson("/api/v1/companies/{$company}/worktime/schedules", $this->schedulePayload())
            ->assertCreated()->json('data.id');

        $this->actingAs($user)
            ->putJson("/api/v1/companies/{$company}/worktime/schedules/{$schedule}", $this->schedulePayload([
                'name' => 'Стандарт 5/2 обновлён',
                'revision' => 1,
            ]))
            ->assertOk()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.name', 'Стандарт 5/2 обновлён');

        $this->actingAs($user)
            ->putJson("/api/v1/companies/{$company}/worktime/schedules/{$schedule}", $this->schedulePayload([
                'revision' => 1,
            ]))
            ->assertStatus(409);

        $this->assertSame(1, DB::table('hr_audit_events')
            ->where('company_id', $company)->where('action', 'worktime.schedule_created')->count());
        $this->assertSame(1, DB::table('hr_audit_events')
            ->where('company_id', $company)->where('action', 'worktime.schedule_updated')->count());
    }

    public function test_assignment_closes_previous_schedule_without_overlap(): void
    {
        config()->set('hrm.worktime_writes_enabled', true);
        [$user, $company, $employee] = $this->fixture();

        $first = $this->actingAs($user)
            ->postJson("/api/v1/companies/{$company}/worktime/schedules", $this->schedulePayload())
            ->assertCreated()->json('data.id');
        $second = $this->actingAs($user)
            ->postJson("/api/v1/companies/{$company}/worktime/schedules", $this->schedulePayload([
                'code' => 'SHIFT-2X2', 'name' => 'Смена 2/2', 'schedule_type' => 'shift',
                'work_time_label' => '08:00–20:00', 'weekly_minutes' => 2520,
            ]))
            ->assertCreated()->json('data.id');

        $this->actingAs($user)
            ->putJson("/api/v1/companies/{$company}/worktime/schedules/{$first}/employees/{$employee}", [
                'valid_from' => '2026-01-01',
            ])
            ->assertOk();

        $this->actingAs($user)
            ->putJson("/api/v1/companies/{$company}/worktime/schedules/{$second}/employees/{$employee}", [
                'valid_from' => '2026-10-01',
            ])
            ->assertOk()
            ->assertJsonPath('data.schedule_id', $second)
            ->assertJsonPath('data.valid_from', '2026-10-01');

        $rows = DB::table('employee_work_schedules')->where('employee_id', $employee)->orderBy('valid_from')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('2026-09-30', $rows[0]->valid_to);
        $this->assertNull($rows[1]->valid_to);
        $this->assertSame($first, $rows[0]->work_schedule_id);
        $this->assertSame($second, $rows[1]->work_schedule_id);
        $this->assertSame(2, DB::table('hr_audit_events')
            ->where('company_id', $company)->where('action', 'worktime.schedule_assigned')->count());
    }

    public function test_assignment_rejects_schedule_or_employee_from_other_company(): void
    {
        config()->set('hrm.worktime_writes_enabled', true);
        [$user, $company, $employee] = $this->fixture();
        [, $otherCompany, $otherEmployee] = $this->fixture();

        $schedule = $this->actingAs($user)
            ->postJson("/api/v1/companies/{$company}/worktime/schedules", $this->schedulePayload())
            ->assertCreated()->json('data.id');

        $this->actingAs($user)
            ->putJson("/api/v1/companies/{$company}/worktime/schedules/{$schedule}/employees/{$otherEmployee}", [
                'valid_from' => '2026-10-01',
            ])
            ->assertNotFound();

        $otherSchedule = (string) Str::uuid();
        DB::table('work_schedules')->insert([
            'id' => $otherSchedule, 'company_id' => $otherCompany, 'code' => 'OTHER',
            'name' => 'Other', 'schedule_type' => 'standard', 'weekly_minutes' => 2400,
            'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($user)
            ->putJson("/api/v1/companies/{$company}/worktime/schedules/{$otherSchedule}/employees/{$employee}", [
                'valid_from' => '2026-10-01',
            ])
            ->assertNotFound();
    }
}
