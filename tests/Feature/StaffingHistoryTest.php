<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StaffingHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_is_scoped_to_company_and_excludes_unrelated_personnel_events(): void
    {
        $user = User::factory()->create();
        $company = (string) Str::uuid();
        $other = (string) Str::uuid();
        DB::table('companies')->insert([
            ['id' => $company, 'name' => 'First'],
            ['id' => $other, 'name' => 'Second'],
        ]);
        DB::table('company_user_roles')->insert([
            'company_id' => $company,
            'user_id' => $user->id,
            'role_code' => 'hr_specialist',
        ]);
        DB::table('hr_audit_events')->insert([
            ['company_id' => $company, 'actor_user_id' => $user->id, 'action' => 'staffing.plan_created', 'context' => json_encode(['staffing_plan_id' => (string) Str::uuid(), 'revision' => 1]), 'created_at' => now()],
            ['company_id' => $company, 'actor_user_id' => $user->id, 'action' => 'personnel.employee_created', 'context' => '{}', 'created_at' => now()],
            ['company_id' => $other, 'actor_user_id' => $user->id, 'action' => 'staffing.plan_approved', 'context' => '{}', 'created_at' => now()],
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/companies/{$company}/staffing-history")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'staffing.plan_created')
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($user)
            ->getJson("/api/v1/companies/{$other}/staffing-history")
            ->assertForbidden();
    }

    public function test_non_hr_member_cannot_read_staffing_history(): void
    {
        $user = User::factory()->create();
        $company = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $company, 'name' => 'Company']);
        DB::table('company_user_roles')->insert([
            'company_id' => $company,
            'user_id' => $user->id,
            'role_code' => 'employee',
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/companies/{$company}/staffing-history")
            ->assertForbidden();
    }
}
