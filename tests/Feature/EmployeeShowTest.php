<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeeShowTest extends TestCase
{
    use RefreshDatabase;

    private function provisionFixture(string $role = 'hr_specialist'): array
    {
        $company=(string) Str::uuid();
        $other=(string) Str::uuid();
        $department=(string) Str::uuid();
        $restricted=(string) Str::uuid();
        $position=(string) Str::uuid();
        $employee=(string) Str::uuid();
        $user=User::factory()->create();
        DB::table('companies')->insert([['id'=>$company,'name'=>'Accessible'],['id'=>$other,'name'=>'Other']]);
        DB::table('departments')->insert([
            ['id'=>$department,'company_id'=>$company,'name'=>'Accessible department'],
            ['id'=>$restricted,'company_id'=>$company,'name'=>'Private department'],
        ]);
        DB::table('positions')->insert(['id'=>$position,'company_id'=>$company,'name'=>'Engineer']);
        DB::table('employees')->insert(['id'=>$employee,'company_id'=>$company,
            'personnel_number'=>'EMP-1','last_name'=>'Test','first_name'=>'Person']);
        DB::table('company_user_roles')->insert(['user_id'=>$user->id,'company_id'=>$company,'role_code'=>$role]);
        DB::table('department_access_scopes')->insert(['user_id'=>$user->id,'company_id'=>$company,'department_id'=>$department]);
        foreach ([[$department,'Accessible department'],[$restricted,'Private department']] as [$id,$label]) {
            DB::table('employee_assignments')->insert([
                'id'=>(string) Str::uuid(),'employee_id'=>$employee,'department_id'=>$id,
                'position_id'=>$position,'valid_from'=>now()->subDays(5)->toDateString(),
            ]);
        }
        return compact('company','other','employee','user');
    }

    public function test_detail_is_private_and_has_bounded_history(): void
    {
        extract($this->provisionFixture());
        $url="/api/v1/companies/{$company}/employees/{$employee}";
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($user)->getJson($url)->assertOk()
            ->assertJsonPath('data.personnel_number','EMP-1')
            ->assertJsonCount(2,'assignments')
            ->assertJsonMissingPath('data.salary')
            ->assertJsonPath('meta.assignments_limit',100);
        $this->actingAs($user)->getJson("/api/v1/companies/{$other}/employees/{$employee}")->assertForbidden();
        $this->actingAs($user)->getJson("/api/v1/companies/{$company}/employees/".Str::uuid())->assertNotFound();
    }

    public function test_department_manager_history_does_not_reveal_other_department(): void
    {
        extract($this->provisionFixture('department_manager'));
        $this->actingAs($user)->getJson("/api/v1/companies/{$company}/employees/{$employee}")
            ->assertOk()->assertJsonCount(1,'assignments')
            ->assertJsonPath('assignments.0.department_name','Accessible department')
            ->assertJsonMissing(['department_name'=>'Private department']);
    }

    public function test_employee_role_is_denied(): void
    {
        extract($this->provisionFixture('employee'));
        $this->actingAs($user)->getJson("/api/v1/companies/{$company}/employees/{$employee}")->assertForbidden();
    }
}
