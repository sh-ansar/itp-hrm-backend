<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SickLeaveWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('minio');
        config()->set([
            'hrm.leave_writes_enabled' => true,
            'hrm.worktime_writes_enabled' => true,
            'hrm.protected_leave_evidence_enabled' => true,
            'hrm.protected_leave_evidence_required' => false,
            'hrm.medical_leave_evidence_required' => true,
            'hrm.leave_manager_approval_enabled' => false,
        ]);
        $company = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $department = (string) Str::uuid();
        $position = (string) Str::uuid();
        $medical = (string) Str::uuid();
        $ordinary = (string) Str::uuid();
        $admin = User::factory()->create();
        $hr = User::factory()->create();
        $staff = User::factory()->create();
        $manager = User::factory()->create();

        DB::table('companies')->insert(['id' => $company, 'name' => 'Test Sick Company']);
        DB::table('departments')->insert(['id' => $department, 'company_id' => $company, 'name' => 'Team']);
        DB::table('positions')->insert(['id' => $position, 'company_id' => $company, 'name' => 'Developer']);
        DB::table('employees')->insert([
            'id' => $employee, 'company_id' => $company, 'personnel_number' => 'SICK-1',
            'first_name' => 'Test', 'last_name' => 'Employee',
        ]);
        DB::table('employee_assignments')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $employee,
            'department_id' => $department, 'position_id' => $position,
            'valid_from' => '2026-01-01', 'revision' => 1,
        ]);
        foreach ([[$admin, 'company_admin'], [$hr, 'hr_specialist'],
            [$staff, 'employee'], [$manager, 'department_manager']] as [$user, $role]) {
            DB::table('company_user_roles')->insert([
                'company_id' => $company, 'user_id' => $user->id, 'role_code' => $role,
            ]);
        }
        DB::table('employee_user_links')->insert([
            'company_id' => $company, 'user_id' => $staff->id,
            'employee_id' => $employee, 'revision' => 1,
        ]);
        DB::table('department_access_scopes')->insert([
            'company_id' => $company, 'user_id' => $manager->id,
            'department_id' => $department,
        ]);
        foreach ([[$medical, 'medical_leave', 'medical'], [$ordinary, 'annual_paid', 'ordinary']] as [$id, $code, $category]) {
            DB::table('leave_types')->insert([
                'id' => $id, 'company_id' => $company, 'code' => $code,
                'name' => $category === 'medical' ? 'Medical leave' : 'Annual leave',
                'category' => $category, 'balance_method' => 'calendar_days',
                'annual_days' => $category === 'ordinary' ? 24 : null, 'revision' => 1,
            ]);
        }
        DB::table('production_calendar_days')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company,
            'work_date' => '2026-10-12', 'day_type' => 'workday',
            'planned_minutes' => 480, 'revision' => 1,
        ]);

        return compact('company', 'employee', 'medical', 'ordinary', 'admin', 'hr', 'staff', 'manager');
    }

    public function test_medical_register_has_server_filtered_pagination_and_no_manager_inference(): void
    {
        extract($this->fixture());
        $base = "/api/v1/companies/{$company}";
        foreach ([$medical, $ordinary] as $leaveType) {
            $this->actingAs($hr)->postJson($base.'/leave-requests', [
                'employee_id' => $employee, 'leave_type_id' => $leaveType,
                'planned_started_on' => $leaveType === $medical ? '2026-10-12' : '2026-11-12',
                'planned_ended_on' => $leaveType === $medical ? '2026-10-12' : '2026-11-12',
                'note' => null,
            ])->assertCreated();
        }
        $this->actingAs($hr)->getJson($base.'/leave-requests?category=medical')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.category', 'medical')
            ->assertJsonPath('meta.medical_evidence_required', true);
        $this->actingAs($hr)->getJson($base.'/leave-requests?category=ordinary')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.category', 'ordinary');
        config()->set('hrm.leave_manager_approval_enabled', true);
        $this->actingAs($manager)->getJson($base.'/leave-requests?category=medical')->assertForbidden();
        $this->actingAs($staff)->getJson($base.'/leave-requests?category=medical')->assertForbidden();
        $this->actingAs($hr)->getJson($base.'/leave-requests?category=diagnosis')
            ->assertUnprocessable()->assertJsonValidationErrors('category');
    }

    public function test_sick_leave_requires_verified_private_pdf_and_projects_without_annual_balance_or_fake_hours(): void
    {
        extract($this->fixture());
        $base = "/api/v1/companies/{$company}";
        $request = $this->actingAs($staff)->postJson($base.'/me/leave-requests', [
            'leave_type_id' => $medical,
            'planned_started_on' => '2026-10-12',
            'planned_ended_on' => '2026-10-12',
            'note' => null,
        ])->assertCreated()->json('data');
        $id = $request['id'];
        $this->actingAs($staff)->postJson($base.'/me/leave-requests/'.$id.'/submit', [
            'revision' => $request['revision'],
        ])->assertOk()->assertJsonPath('data.status', 'submitted');

        $this->actingAs($admin)->postJson($base.'/leave-requests/'.$id.'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertUnprocessable()->assertJsonValidationErrors('evidence');
        $this->assertDatabaseCount('employee_leaves', 0);
        $this->actingAs($staff)->post($base.'/leave-requests/'.$id.'/evidence/file', [
            'request_revision' => 2,
            'file' => UploadedFile::fake()->createWithContent('medical.pdf',
                "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'pending');

        $this->actingAs($hr)->postJson($base.'/leave-requests/'.$id.'/evidence/review', [
            'revision' => 1, 'decision' => 'verified',
        ])->assertOk()->assertJsonPath('data.status', 'verified');
        $this->actingAs($admin)->postJson($base.'/leave-requests/'.$id.'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseCount('employee_leaves', 1);
        $this->assertDatabaseCount('leave_balance_periods', 0);
        $this->assertDatabaseCount('worktime_entries', 0);
        $this->actingAs($hr)->getJson($base.'/worktime/absences?month=2026-10')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reason', 'Больничный')
            ->assertJsonPath('data.0.status', 'planned');
        $this->actingAs($staff)->getJson($base.'/me/timesheet?month=2026-10')
            ->assertOk()->assertJsonPath('data.0.medical_leave_state', 'planned')
            ->assertJsonPath('data.0.worked_minutes', null)
            ->assertJsonPath('data.0.absence_minutes', null);
        $this->actingAs($manager)->getJson($base.'/worktime/absences?month=2026-10')
            ->assertOk()->assertJsonPath('data.0.reason', 'Отсутствие')
            ->assertJsonPath('data.0.absence_type', 'restricted');
    }
}
