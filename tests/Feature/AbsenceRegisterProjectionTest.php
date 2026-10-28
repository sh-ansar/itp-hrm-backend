<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AbsenceRegisterProjectionTest extends TestCase
{
    use RefreshDatabase;

    private function scope(string $role = 'hr_specialist'): array
    {
        $user = User::factory()->create();
        $company = (string) Str::uuid();
        $department = (string) Str::uuid();
        $position = (string) Str::uuid();
        $employee = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $company, 'name' => 'Example']);
        DB::table('departments')->insert(['id' => $department, 'company_id' => $company, 'name' => 'Operations']);
        DB::table('positions')->insert(['id' => $position, 'company_id' => $company, 'name' => 'Operator']);
        DB::table('company_user_roles')->insert(['user_id' => $user->id, 'company_id' => $company, 'role_code' => $role]);
        DB::table('employees')->insert([
            'id' => $employee, 'company_id' => $company, 'personnel_number' => 'ABS-001',
            'last_name' => 'Example', 'first_name' => 'Employee', 'revision' => 1,
        ]);
        DB::table('employee_assignments')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $employee,
            'department_id' => $department, 'position_id' => $position,
            'valid_from' => '2026-01-01', 'valid_to' => null, 'revision' => 1,
        ]);
        if ($role === 'department_manager') {
            DB::table('department_access_scopes')->insert([
                'company_id' => $company, 'user_id' => $user->id, 'department_id' => $department,
            ]);
        }
        return [$user, $company, $employee];
    }

    private function leave(string $company, string $employee, string $category = 'annual', ?string $cancelled = null): string
    {
        $typeId = (string) Str::uuid();
        $leaveId = (string) Str::uuid();
        DB::table('leave_types')->insert([
            'id' => $typeId, 'company_id' => $company, 'name' => 'Sensitive leave',
            'code' => $category === 'medical' ? 'medical_leave' : 'annual_paid',
            'category' => $category, 'revision' => 1,
        ]);
        DB::table('employee_leaves')->insert([
            'id' => $leaveId, 'employee_id' => $employee, 'leave_type_id' => $typeId,
            'planned_started_on' => '2026-10-12', 'planned_ended_on' => '2026-10-14',
            'planned_days' => 3, 'actual_started_on' => '2026-10-12',
            'actual_ended_on' => '2026-10-14', 'actual_days' => 3,
            'revision' => 1, 'cancelled_at' => $cancelled,
        ]);
        return $leaveId;
    }

    public function test_historical_leave_is_visible_without_fake_approval_or_duplicate_absence(): void
    {
        [$hr, $company, $employee] = $this->scope();
        $leaveId = $this->leave($company, $employee);
        $url = "/api/v1/companies/{$company}/worktime/absences?month=2026-10&per_page=1";
        $this->actingAs($hr)->getJson($url)->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.sources.1', 'leave')
            ->assertJsonPath('data.0.id', 'leave:'.$leaveId)
            ->assertJsonPath('data.0.source', 'leave')
            ->assertJsonPath('data.0.status', 'historical')
            ->assertJsonPath('data.0.days', 3);

        DB::table('employee_absences')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $employee,
            'absence_type' => 'other', 'reason' => 'Explicit absence',
            'started_on' => '2026-10-13', 'ended_on' => '2026-10-13',
            'absence_minutes' => 480, 'status' => 'recorded', 'revision' => 1,
        ]);
        $this->actingAs($hr)->getJson($url)->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.source', 'absence')
            ->assertJsonPath('data.0.reason', 'Explicit absence');
        $this->assertDatabaseCount('employee_leaves', 1);
        $this->assertDatabaseCount('employee_absences', 1);
    }

    public function test_manager_sees_scoped_period_but_not_medical_details(): void
    {
        [$manager, $company, $employee] = $this->scope('department_manager');
        $this->leave($company, $employee, 'medical');
        $url = "/api/v1/companies/{$company}/worktime/absences?month=2026-10";
        $this->actingAs($manager)->getJson($url)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', $employee)
            ->assertJsonPath('data.0.absence_type', 'restricted')
            ->assertJsonPath('data.0.reason', 'Отсутствие')
            ->assertJsonMissing(['reason' => 'Sensitive leave']);

        $plain = User::factory()->create();
        DB::table('company_user_roles')->insert([
            'user_id' => $plain->id, 'company_id' => $company, 'role_code' => 'employee',
        ]);
        $this->actingAs($plain)->getJson($url)->assertForbidden();

        $foreign = User::factory()->create();
        $foreignCompany = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $foreignCompany, 'name' => 'Other']);
        DB::table('company_user_roles')->insert([
            'user_id' => $foreign->id, 'company_id' => $foreignCompany,
            'role_code' => 'company_admin',
        ]);
        $this->actingAs($foreign)->getJson($url)->assertForbidden();
    }

    public function test_cancelled_leave_and_outside_month_never_appear(): void
    {
        [$hr, $company, $employee] = $this->scope();
        $this->leave($company, $employee, 'annual', '2026-10-15 00:00:00');
        $this->actingAs($hr)
            ->getJson("/api/v1/companies/{$company}/worktime/absences?month=2026-10")
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($hr)
            ->getJson("/api/v1/companies/{$company}/worktime/absences?month=2026-11")
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
