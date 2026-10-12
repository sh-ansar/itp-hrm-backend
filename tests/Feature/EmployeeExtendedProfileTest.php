<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeeExtendedProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_sees_extended_fields_and_manager_cannot_read_them(): void
    {
        $company=(string) Str::uuid();
        $department=(string) Str::uuid();
        $position=(string) Str::uuid();
        $employee=(string) Str::uuid();
        $user=User::factory()->create();

        DB::table('companies')->insert(['id'=>$company,'name'=>'Demo']);
        DB::table('departments')->insert(['id'=>$department,'company_id'=>$company,'name'=>'HR']);
        DB::table('positions')->insert(['id'=>$position,'company_id'=>$company,'name'=>'Engineer']);
        DB::table('employees')->insert(['id'=>$employee,'company_id'=>$company,
            'personnel_number'=>'E-1','last_name'=>'Sample','first_name'=>'Worker']);
        DB::table('employee_assignments')->insert(['id'=>(string)Str::uuid(),
            'employee_id'=>$employee,'department_id'=>$department,'position_id'=>$position,'valid_from'=>'2025-01-01']);
        DB::table('employee_experience')->insert(['id'=>(string)Str::uuid(),'employee_id'=>$employee,
            'experience_type'=>'professional','started_on'=>'2021-01-01']);
        DB::table('employee_reserve')->insert(['id'=>(string)Str::uuid(),'employee_id'=>$employee,
            'target_position'=>'Supervisor','reserve_level'=>'management','reserve_type'=>'internal','joined_year'=>2025]);
        DB::table('employee_training')->insert(['id'=>(string)Str::uuid(),'employee_id'=>$employee,
            'training_type'=>'seminar','training_mode'=>'remote','topic'=>'Safety','started_on'=>'2026-02-01']);
        DB::table('company_user_roles')->insert(['user_id'=>$user->id,'company_id'=>$company,'role_code'=>'hr_specialist']);
        $endpoint="/api/v1/companies/{$company}/employees/{$employee}";
        $this->actingAs($user)->getJson($endpoint)->assertOk()
            ->assertJsonPath('meta.extended_sections_visible',true)
            ->assertJsonPath('meta.profile_writes_enabled',false)
            ->assertJsonCount(1,'experience')->assertJsonCount(1,'reserve')
            ->assertJsonCount(1,'training')->assertJsonPath('training.0.topic','Safety');
        DB::table('company_user_roles')->where('user_id',$user->id)->update(['role_code'=>'department_manager']);
        DB::table('department_access_scopes')->insert(['user_id'=>$user->id,'company_id'=>$company,'department_id'=>$department]);
        $this->actingAs($user)->getJson($endpoint)->assertOk()
            ->assertJsonPath('meta.extended_sections_visible',false)
            ->assertJsonPath('meta.profile_writes_enabled',false)
            ->assertJsonCount(0,'experience')->assertJsonCount(0,'reserve')->assertJsonCount(0,'training');
    }
}
