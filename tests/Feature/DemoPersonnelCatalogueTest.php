<?php

namespace Tests\Feature;

use App\Domain\Demo\DemoPersonnelCatalogue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class DemoPersonnelCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private function setupTargets(): User
    {
        config()->set(['hrm.personnel_writes_enabled' => true, 'hrm.worktime_writes_enabled' => true]);
        $actor = User::factory()->create();
        $actor->forceFill(['account_status' => 'active'])->save();
        foreach (['ITP Mining', 'ITP Solutions', 'Unrelated company'] as $name) {
            $id = (string) Str::uuid();
            DB::table('companies')->insert(['id' => $id, 'name' => $name]);
            DB::table('company_user_roles')->insert(['company_id' => $id, 'user_id' => $actor->id, 'role_code' => 'company_admin']);
        }
        return $actor->refresh();
    }

    public function test_repeatable_catalogue_preserves_other_company_and_creates_real_hire_history(): void
    {
        $actor = $this->setupTargets();
        $result = app(DemoPersonnelCatalogue::class)->apply($actor);
        $this->assertSame(80, $result['ITP Mining']['active']);
        $this->assertSame(20, $result['ITP Solutions']['active']);
        $this->assertDatabaseCount('employees', 100);
        $this->assertDatabaseCount('employee_assignments', 100);
        $this->assertDatabaseCount('personnel_actions', 100);
        $this->assertDatabaseCount('employee_contracts', 100);
        $this->assertDatabaseCount('employee_training', 100);
        $this->assertDatabaseCount('employee_education_records', 100);
        $this->assertDatabaseCount('employee_work_schedules', 100);
        $this->assertDatabaseCount('departments', 14);
        $audits = DB::table('hr_audit_events')->count();
        app(DemoPersonnelCatalogue::class)->apply($actor);
        $this->assertDatabaseCount('employees', 100);
        $this->assertDatabaseCount('hr_audit_events', $audits);
        $unrelated = DB::table('companies')->where('name', 'Unrelated company')->value('id');
        $this->assertSame(0, DB::table('employees')->where('company_id', $unrelated)->count());
        $this->assertSame(100, DB::table('employees')->where('email', 'like', '%@hrm.example.test')->whereNull('phone')->count());
        $this->assertSame(0, DB::table('employee_assignments as a')->join('employees as e', 'e.id', '=', 'a.employee_id')
            ->join('departments as d', 'd.id', '=', 'a.department_id')->whereColumn('e.company_id', '<>', 'd.company_id')->count());
    }

    public function test_existing_employee_and_assignment_are_preserved(): void
    {
        $actor = $this->setupTargets();
        $company = DB::table('companies')->where('name', 'ITP Mining')->value('id');
        $employee = (string) Str::uuid();
        $department = (string) Str::uuid();
        $position = (string) Str::uuid();
        DB::table('departments')->insert(['id' => $department, 'company_id' => $company, 'name' => 'Existing department']);
        DB::table('positions')->insert(['id' => $position, 'company_id' => $company, 'name' => 'Existing position']);
        DB::table('employees')->insert(['id' => $employee, 'company_id' => $company, 'personnel_number' => 'KEEP-001', 'first_name' => 'Existing', 'last_name' => 'Employee']);
        DB::table('employee_assignments')->insert(['id' => (string) Str::uuid(), 'employee_id' => $employee,
            'department_id' => $department, 'position_id' => $position, 'valid_from' => '2025-01-01']);
        $before = (array) DB::table('employees')->where('id', $employee)->first();
        $result = app(DemoPersonnelCatalogue::class)->apply($actor);
        $this->assertSame(79, $result['ITP Mining']['created']);
        $this->assertDatabaseCount('employees', 100);
        $this->assertSame($before, (array) DB::table('employees')->where('id', $employee)->first());
        $this->assertSame(1, DB::table('employee_assignments')->where('employee_id', $employee)->count());
    }

    public function test_command_rejects_any_non_approved_database(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('isolated HRM database');
        $this->artisan('hrm:demo-personnel', ['--target' => 'hrm-demo-20261027']);
    }

    public function test_prefix_collision_rolls_back_the_entire_catalogue(): void
    {
        $actor = $this->setupTargets();
        $company = DB::table('companies')->where('name', 'ITP Solutions')->value('id');
        DB::table('employees')->insert(['id' => (string) Str::uuid(), 'company_id' => $company,
            'personnel_number' => 'DEMO26-S-001', 'first_name' => 'Existing', 'last_name' => 'Record']);
        try {
            app(DemoPersonnelCatalogue::class)->apply($actor);
            $this->fail('Conflicting personnel number must not be adopted.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('collision', $error->getMessage());
        }
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('employee_assignments', 0);
        $this->assertDatabaseCount('departments', 0);
    }

    public function test_insufficient_company_scope_never_mutates_records(): void
    {
        $actor = $this->setupTargets();
        DB::table('company_user_roles')->where('user_id', $actor->id)->update(['role_code' => 'employee']);
        try {
            app(DemoPersonnelCatalogue::class)->apply($actor);
            $this->fail('Employee must not populate the catalogue.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('administrator', $error->getMessage());
        }
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('departments', 0);
    }

    public function test_command_refuses_unapproved_target_and_unit_database(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('approved target');
        $this->artisan('hrm:demo-personnel', ['--target' => 'unapproved']);
    }
}
