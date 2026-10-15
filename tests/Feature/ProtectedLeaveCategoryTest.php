<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProtectedLeaveCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_long_childcare_leave_keeps_category_and_cannot_use_workday_counting(): void
    {
        config()->set('hrm.leave_writes_enabled', true);
        $company = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $admin = User::factory()->create();
        DB::table('companies')->insert(['id' => $company, 'name' => 'Synthetic']);
        DB::table('employees')->insert([
            'id' => $employee, 'company_id' => $company,
            'personnel_number' => 'TEST-1', 'first_name' => 'Example', 'last_name' => 'Employee',
        ]);
        DB::table('company_user_roles')->insert([
            'company_id' => $company, 'user_id' => $admin->id, 'role_code' => 'company_admin',
        ]);
        $typesUrl = "/api/v1/companies/{$company}/leave-types";
        $type = $this->actingAs($admin)->postJson($typesUrl, [
            'code' => 'childcare', 'name' => 'Childcare leave',
            'annual_days' => null, 'balance_method' => 'calendar_days', 'category' => 'childcare',
        ])->assertCreated()->assertJsonPath('data.category', 'childcare')->json('data.id');
        $this->actingAs($admin)->getJson($typesUrl)
            ->assertOk()->assertJsonPath('data.0.category', 'childcare');

        $draft = [
            'employee_id' => $employee, 'leave_type_id' => $type,
            'planned_started_on' => '2027-01-01', 'planned_ended_on' => '2028-02-04',
            'note' => null,
        ];
        $this->actingAs($admin)->postJson("/api/v1/companies/{$company}/leave-requests", $draft)
            ->assertCreated()->assertJsonPath('data.planned_days', 400);

        $this->actingAs($admin)->postJson($typesUrl, [
            'code' => 'medical_wrong', 'name' => 'Medical leave',
            'annual_days' => null, 'balance_method' => 'working_days', 'category' => 'medical',
        ])->assertCreated();
        $wrong = DB::table('leave_types')->where('company_id', $company)->where('code', 'medical_wrong')->value('id');
        $draft['leave_type_id'] = $wrong;
        $draft['planned_started_on'] = '2029-01-01';
        $draft['planned_ended_on'] = '2029-01-10';
        $this->actingAs($admin)->postJson("/api/v1/companies/{$company}/leave-requests", $draft)
            ->assertUnprocessable();

        $this->assertDatabaseCount('leave_requests', 1);
    }
}
