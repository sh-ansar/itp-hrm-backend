<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeeRegistryStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_filters_effective_status_before_pagination(): void
    {
        $company = (string) Str::uuid();
        $anotherCompany = (string) Str::uuid();
        $department = (string) Str::uuid();
        $position = (string) Str::uuid();
        $user = User::factory()->create();
        $now = now();

        DB::table('companies')->insert([
            ['id' => $company, 'name' => 'Synthetic company'],
            ['id' => $anotherCompany, 'name' => 'Other company'],
        ]);
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id,
            'company_id' => $company,
            'role_code' => 'hr_specialist',
        ]);
        DB::table('departments')->insert(['id' => $department, 'company_id' => $company, 'name' => 'HR']);
        DB::table('positions')->insert(['id' => $position, 'company_id' => $company, 'name' => 'Analyst']);
        $employees = [];
        foreach (['ACTIVE', 'PLANNED', 'ARCHIVED', 'UNASSIGNED'] as $index => $kind) {
            $id = (string) Str::uuid();
            $employees[$kind] = $id;
            DB::table('employees')->insert([
                'id' => $id, 'company_id' => $company,
                'personnel_number' => 'TEST-'.$kind,
                'last_name' => 'Employee'.($index + 1),
                'first_name' => 'Synthetic',
                'revision' => 1,
            ]);
        }
        DB::table('employees')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $anotherCompany,
            'personnel_number' => 'OUTSIDE', 'last_name' => 'Hidden',
            'first_name' => 'Person', 'revision' => 1,
        ]);
        foreach ([
            'ACTIVE' => [$now->copy()->subDays(30)->toDateString(), null],
            'PLANNED' => [$now->copy()->addDays(10)->toDateString(), null],
            'ARCHIVED' => [$now->copy()->subDays(90)->toDateString(), $now->copy()->subDays(5)->toDateString()],
        ] as $kind => [$from, $to]) {
            DB::table('employee_assignments')->insert([
                'id' => (string) Str::uuid(), 'employee_id' => $employees[$kind],
                'department_id' => $department, 'position_id' => $position,
                'valid_from' => $from, 'valid_to' => $to, 'revision' => 1,
            ]);
        }

        foreach ([
            'all' => [4, null],
            'active' => [1, 'ACTIVE'],
            'planned' => [1, 'PLANNED'],
            'archived' => [1, 'ARCHIVED'],
            'unassigned' => [1, 'UNASSIGNED'],
        ] as $status => [$count, $expected]) {
            $response = $this->actingAs($user)->getJson(
                "/api/v1/companies/{$company}/employees?status={$status}&per_page=1"
            )->assertOk()->assertJsonPath('meta.total', $count)
                ->assertJsonPath('meta.status_filter', $status)
                ->assertJsonCount(1, 'data');

            if ($expected !== null) {
                $response->assertJsonPath('data.0.id', $employees[$expected])
                    ->assertJsonPath('data.0.employment_status', $status);
            }
        }

        $this->actingAs($user)
            ->getJson("/api/v1/companies/{$company}/employees?status=archived&search=TEST-ARCHIVED")
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($user)
            ->getJson("/api/v1/companies/{$anotherCompany}/employees?status=archived")
            ->assertForbidden();
        $this->actingAs($user)
            ->getJson("/api/v1/companies/{$company}/employees?status=broken")
            ->assertUnprocessable();
    }
}
