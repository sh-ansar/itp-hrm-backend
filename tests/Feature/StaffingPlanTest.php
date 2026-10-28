<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffingPlanTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $role = 'hr_specialist'): array
    {
        $company = (string) Str::uuid();
        $otherCompany = (string) Str::uuid();
        $department = (string) Str::uuid();
        $otherDepartment = (string) Str::uuid();
        $position = (string) Str::uuid();
        $otherPosition = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $user = User::factory()->create();

        DB::table('companies')->insert([
            ['id' => $company, 'name' => 'Primary'],
            ['id' => $otherCompany, 'name' => 'Other'],
        ]);
        DB::table('departments')->insert([
            ['id' => $department, 'company_id' => $company, 'name' => 'Operations'],
            ['id' => $otherDepartment, 'company_id' => $otherCompany, 'name' => 'Hidden'],
        ]);
        DB::table('positions')->insert([
            ['id' => $position, 'company_id' => $company, 'name' => 'Engineer'],
            ['id' => $otherPosition, 'company_id' => $otherCompany, 'name' => 'Hidden role'],
        ]);
        DB::table('employees')->insert([
            'id' => $employee,
            'company_id' => $company,
            'personnel_number' => 'EMP-ST-1',
            'last_name' => 'Staff',
            'first_name' => 'Member',
            'revision' => 1,
        ]);
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id,
            'company_id' => $company,
            'role_code' => $role,
        ]);

        config()->set('hrm.personnel_writes_enabled', true);

        return compact(
            'company',
            'otherCompany',
            'department',
            'otherDepartment',
            'position',
            'otherPosition',
            'employee',
            'user',
        );
    }

    private function createPlan(User $user, string $company, array $override = []): array
    {
        $payload = array_merge([
            'name' => 'Staffing 2027',
            'valid_from' => '2027-01-01',
            'valid_to' => '2027-12-31',
        ], $override);

        return $this->actingAs($user)
            ->postJson("/api/v1/companies/{$company}/staffing-plans", $payload)
            ->assertCreated()
            ->json('data');
    }

    public function test_hr_can_create_line_and_read_occupancy_projection(): void
    {
        extract($this->fixture());
        $plan = $this->createPlan($user, $company);

        DB::table('employee_assignments')->insert([
            'id' => (string) Str::uuid(),
            'employee_id' => $employee,
            'department_id' => $department,
            'position_id' => $position,
            'valid_from' => '2027-01-01',
            'valid_to' => null,
            'revision' => 1,
        ]);

        $this->actingAs($user)->postJson("/api/v1/companies/{$company}/staffing-plans/{$plan['id']}/lines", [
            'department_id' => $department,
            'position_id' => $position,
            'planned_units' => 3,
            'base_salary' => 450000,
            'currency' => 'KZT',
            'notes' => 'Core engineering line',
        ])->assertCreated();

        $this->actingAs($user)
            ->getJson("/api/v1/companies/{$company}/staffing-plans/{$plan['id']}?as_of=2027-06-01")
            ->assertOk()
            ->assertJsonPath('lines.0.department_name', 'Operations')
            ->assertJsonPath('lines.0.position_name', 'Engineer')
            ->assertJsonPath('lines.0.planned_units', 3)
            ->assertJsonPath('lines.0.occupied_units', 1)
            ->assertJsonPath('lines.0.vacant_units', 2)
            ->assertJsonPath('lines.0.overstaffed_units', 0)
            ->assertJsonPath('lines.0.base_salary', 450000)
            ->assertJsonPath('lines.0.currency', 'KZT');
    }

    public function test_cross_company_directories_and_manager_writes_are_denied(): void
    {
        extract($this->fixture());
        $plan = $this->createPlan($user, $company);

        $this->actingAs($user)->postJson("/api/v1/companies/{$company}/staffing-plans/{$plan['id']}/lines", [
            'department_id' => $otherDepartment,
            'position_id' => $otherPosition,
            'planned_units' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['directory']);

        DB::table('company_user_roles')->where('user_id', $user->id)->update(['role_code' => 'department_manager']);

        $this->actingAs($user)->postJson("/api/v1/companies/{$company}/staffing-plans", [
            'name' => 'Denied',
            'valid_from' => '2028-01-01',
            'valid_to' => null,
        ])->assertNotFound();
    }

    public function test_approval_requires_lines_and_prevents_overlapping_approved_versions(): void
    {
        extract($this->fixture());
        $first = $this->createPlan($user, $company, [
            'name' => '2027 first',
            'valid_from' => '2027-01-01',
            'valid_to' => '2027-06-30',
        ]);

        $this->actingAs($user)->postJson("/api/v1/companies/{$company}/staffing-plans/{$first['id']}/approve", [
            'revision' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['plan']);

        $this->actingAs($user)->postJson("/api/v1/companies/{$company}/staffing-plans/{$first['id']}/lines", [
            'department_id' => $department,
            'position_id' => $position,
            'planned_units' => 2,
        ])->assertCreated();

        $this->actingAs($user)->postJson("/api/v1/companies/{$company}/staffing-plans/{$first['id']}/approve", [
            'revision' => 1,
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.revision', 2);

        $second = $this->createPlan($user, $company, [
            'name' => '2027 overlapping',
            'valid_from' => '2027-06-01',
            'valid_to' => '2027-12-31',
        ]);
        $this->actingAs($user)->postJson("/api/v1/companies/{$company}/staffing-plans/{$second['id']}/lines", [
            'department_id' => $department,
            'position_id' => $position,
            'planned_units' => 3,
        ])->assertCreated();

        $this->actingAs($user)->postJson("/api/v1/companies/{$company}/staffing-plans/{$second['id']}/approve", [
            'revision' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['valid_from']);

        $this->assertDatabaseHas('staffing_plans', [
            'id' => $second['id'],
            'status' => 'draft',
            'revision' => 1,
        ]);
    }

    public function test_approved_plan_is_immutable_and_stale_draft_revision_conflicts(): void
    {
        extract($this->fixture());
        $plan = $this->createPlan($user, $company);

        $this->actingAs($user)->putJson("/api/v1/companies/{$company}/staffing-plans/{$plan['id']}", [
            'name' => 'Updated draft',
            'valid_from' => '2027-01-01',
            'valid_to' => '2027-12-31',
            'revision' => 1,
        ])->assertOk()->assertJsonPath('data.revision', 2);

        $this->actingAs($user)->putJson("/api/v1/companies/{$company}/staffing-plans/{$plan['id']}", [
            'name' => 'Stale',
            'valid_from' => '2027-01-01',
            'valid_to' => '2027-12-31',
            'revision' => 1,
        ])->assertConflict();

        $this->actingAs($user)->postJson("/api/v1/companies/{$company}/staffing-plans/{$plan['id']}/lines", [
            'department_id' => $department,
            'position_id' => $position,
            'planned_units' => 1,
        ])->assertCreated();

        $this->actingAs($user)->postJson("/api/v1/companies/{$company}/staffing-plans/{$plan['id']}/approve", [
            'revision' => 2,
        ])->assertOk();

        $this->actingAs($user)->putJson("/api/v1/companies/{$company}/staffing-plans/{$plan['id']}", [
            'name' => 'No longer editable',
            'valid_from' => '2027-01-01',
            'valid_to' => '2027-12-31',
            'revision' => 3,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['plan']);

        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company,
            'actor_user_id' => $user->id,
            'action' => 'staffing.plan_approved',
        ]);
    }

    public function test_new_plan_can_copy_staffing_lines_atomically_from_existing_version(): void
    {
        extract($this->fixture());
        $source = $this->createPlan($user, $company, [
            'name' => 'Source plan', 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31',
        ]);
        $this->actingAs($user)->postJson("/api/v1/companies/{$company}/staffing-plans/{$source['id']}/lines", [
            'department_id' => $department,
            'position_id' => $position,
            'planned_units' => 3,
            'base_salary' => 550000,
            'currency' => 'KZT',
            'notes' => 'Preserve this staffing line',
        ])->assertCreated();
        $base = "/api/v1/companies/{$company}/staffing-plans";
        $this->getJson($base)->assertOk()->assertJsonPath('meta.clone_supported', true);
        $result = $this->postJson($base, [
            'name' => 'Next year — draft', 'valid_from' => '2027-01-01',
            'valid_to' => '2027-12-31',
            'source_plan_id' => $source['id'],
            'source_plan_revision' => $source['revision'],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.copied_lines', 1)->json('data');

        $this->assertDatabaseCount('staffing_plans', 2);
        $this->assertDatabaseCount('staffing_plan_lines', 2);
        $original = DB::table('staffing_plan_lines')->where('staffing_plan_id', $source['id'])->first();
        $cloned = DB::table('staffing_plan_lines')->where('staffing_plan_id', $result['id'])->first();
        $this->assertNotEquals($original->id, $cloned->id);
        $this->assertEquals($original->department_id, $cloned->department_id);
        $this->assertEquals($original->position_id, $cloned->position_id);
        $this->assertEquals($original->planned_units, $cloned->planned_units);
        $this->assertEquals($original->base_salary, $cloned->base_salary);
        $this->assertEquals($original->currency, $cloned->currency);
        $this->assertEquals($original->notes, $cloned->notes);
        $this->assertEquals(1, $cloned->revision);
        $this->assertEquals('draft', DB::table('staffing_plans')->where('id', $result['id'])->value('status'));

        // Editing a copied line cannot mutate the approved or original version.
        $this->postJson("$base/{$result['id']}/approve", ['revision' => 1])->assertOk();
        $this->assertEquals('draft', DB::table('staffing_plans')->where('id', $source['id'])->value('status'));
    }

    public function test_source_must_belong_to_same_company_and_match_current_revision(): void
    {
        extract($this->fixture());
        $source = $this->createPlan($user, $company);
        $base = "/api/v1/companies/{$company}/staffing-plans";
        $payload = [
            'name' => 'Copied draft', 'valid_from' => '2028-01-01',
            'valid_to' => null, 'source_plan_id' => $source['id'],
            'source_plan_revision' => $source['revision'],
        ];

        DB::table('staffing_plans')->where('id', $source['id'])->increment('revision');
        $this->postJson($base, $payload)->assertStatus(409);
        $this->assertDatabaseCount('staffing_plans', 1);

        DB::table('staffing_plans')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $otherCompany,
            'name' => 'Foreign plan', 'valid_from' => '2026-01-01',
            'status' => 'draft', 'revision' => 1,
        ]);
        $foreign = DB::table('staffing_plans')->where('company_id', $otherCompany)->value('id');
        $this->postJson($base, [...$payload, 'source_plan_id' => $foreign])
            ->assertUnprocessable()->assertJsonValidationErrors('source_plan_id');
        $this->assertDatabaseCount('staffing_plans', 2);
    }
}
