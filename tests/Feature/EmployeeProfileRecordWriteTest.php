<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeeProfileRecordWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_by_default_even_for_authenticated_hr(): void
    {
        $u=User::factory()->create();
        $this->actingAs($u)->postJson('/api/v1/companies/'.Str::uuid().'/employees/'.Str::uuid().'/profile/training',[])
            ->assertNotFound();
    }

    public function test_company_role_validation_and_audit_are_transactional(): void
    {
        config()->set('hrm.personnel_writes_enabled',true);
        $company=(string)Str::uuid();$other=(string)Str::uuid();$employee=(string)Str::uuid();
        $user=User::factory()->create();
        DB::table('companies')->insert([['id'=>$company,'name'=>'A'],['id'=>$other,'name'=>'B']]);
        DB::table('employees')->insert(['id'=>$employee,'company_id'=>$company,'personnel_number'=>'T-1','last_name'=>'Example','first_name'=>'Sample']);
        $url="/api/v1/companies/{$company}/employees/{$employee}/profile/training";
        $payload=['training_type'=>'seminar','training_mode'=>'remote','topic'=>'Safety','started_on'=>'2026-01-01'];
        $this->postJson($url,$payload)->assertUnauthorized();
        $this->actingAs($user)->postJson($url,$payload)->assertForbidden();
        DB::table('company_user_roles')->insert(['company_id'=>$company,'user_id'=>$user->id,'role_code'=>'hr_specialist']);
        $this->actingAs($user)->postJson($url,['training_type'=>'seminar','training_mode'=>'invalid','topic'=>'X','started_on'=>'2026-01-01'])->assertUnprocessable();
        $this->actingAs($user)->postJson($url,$payload)->assertCreated()->assertJsonPath('data.section','training');
        $this->assertDatabaseCount('employee_training',1);
        $this->assertDatabaseHas('hr_audit_events',['company_id'=>$company,'action'=>'personnel.profile_record_created']);
        $this->actingAs($user)->postJson("/api/v1/companies/{$other}/employees/{$employee}/profile/training",$payload)->assertForbidden();
        $this->assertDatabaseCount('employee_training',1);
    }
}
