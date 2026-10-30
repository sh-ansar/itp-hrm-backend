<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LeaveTypeEditTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config()->set('hrm.leave_writes_enabled',true);
        $admin=User::factory()->create();
        $hr=User::factory()->create();
        $company=(string) Str::uuid();
        $type=(string) Str::uuid();
        $employee=(string) Str::uuid();
        DB::table('companies')->insert(['id'=>$company,'name'=>'Synthetic Georgian test']);
        DB::table('company_user_roles')->insert([
            ['company_id'=>$company,'user_id'=>$admin->id,'role_code'=>'company_admin'],
            ['company_id'=>$company,'user_id'=>$hr->id,'role_code'=>'hr_specialist'],
        ]);
        DB::table('employees')->insert([
            'id'=>$employee,'company_id'=>$company,'personnel_number'=>'GE-SYN-1',
            'last_name'=>'Synthetic','first_name'=>'Employee',
        ]);
        DB::table('leave_types')->insert([
            'id'=>$type,'company_id'=>$company,'code'=>'annual_paid',
            'name'=>'Annual leave','annual_days'=>24,
            'balance_method'=>'calendar_days','category'=>'ordinary','revision'=>1,
        ]);
        return compact('admin','hr','company','type','employee');
    }

    private function payload(int $revision=1,string $method='working_days'): array
    {
        return [
            'revision'=>$revision,'name'=>'Annual paid leave',
            'annual_days'=>24,'balance_method'=>$method,
            'reason'=>'Reviewed Georgian 24 working-day policy',
        ];
    }

    private function url(array $fx): string
    {
        return "/api/v1/companies/{$fx['company']}/leave-types/{$fx['type']}";
    }

    public function test_a_company_admin_can_update_policy_with_revision_and_audit(): void
    {
        $fx=$this->fixture();
        $this->actingAs($fx['admin'])->putJson($this->url($fx),$this->payload())
            ->assertOk()->assertJsonPath('data.balance_method','working_days')
            ->assertJsonPath('data.revision',2)
            ->assertJsonPath('data.code','annual_paid');
        $this->assertDatabaseHas('leave_types',[
            'id'=>$fx['type'],'balance_method'=>'working_days','revision'=>2,
        ]);
        $this->assertDatabaseHas('hr_audit_events',[
            'company_id'=>$fx['company'],'action'=>'leave.type_updated',
        ]);
        $this->putJson($this->url($fx),$this->payload())->assertStatus(409);
    }

    public function test_hr_cannot_change_global_leave_rules_and_extra_fields_are_denied(): void
    {
        $fx=$this->fixture();
        $this->actingAs($fx['hr'])->putJson($this->url($fx),$this->payload())
            ->assertForbidden();
        $this->actingAs($fx['admin'])->putJson($this->url($fx),[
            ...$this->payload(),'code'=>'not_allowed',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('leave_types',[
            'id'=>$fx['type'],'balance_method'=>'calendar_days','revision'=>1,
        ]);
    }

    public function test_policy_change_rolls_back_when_pending_day_totals_would_change(): void
    {
        $fx=$this->fixture();
        for($day=2;$day<=9;$day++){
            $date=sprintf('2026-11-%02d',$day);
            $weekday=(int)\Carbon\CarbonImmutable::parse($date)->dayOfWeekIso;
            DB::table('production_calendar_days')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$fx['company'],
                'work_date'=>$date,'day_type'=>$weekday>5?'weekend':'workday',
                'planned_minutes'=>$weekday>5?0:480,'revision'=>1,
            ]);
        }
        DB::table('leave_requests')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$fx['company'],
            'employee_id'=>$fx['employee'],'leave_type_id'=>$fx['type'],
            'planned_started_on'=>'2026-11-02','planned_ended_on'=>'2026-11-09',
            'planned_days'=>8,'status'=>'submitted','revision'=>1,
            'created_by_user_id'=>$fx['hr']->id,
        ]);
        $this->actingAs($fx['admin'])->putJson($this->url($fx),$this->payload())
            ->assertStatus(409);
        $this->assertDatabaseHas('leave_types',[
            'id'=>$fx['type'],'balance_method'=>'calendar_days','revision'=>1,
        ]);
        $this->assertDatabaseMissing('hr_audit_events',[
            'company_id'=>$fx['company'],'action'=>'leave.type_updated',
        ]);
    }

    public function test_matching_pending_five_workdays_are_compatible_with_new_method(): void
    {
        $fx=$this->fixture();
        for($day=2;$day<=6;$day++){
            DB::table('production_calendar_days')->insert([
                'id'=>(string)Str::uuid(),'company_id'=>$fx['company'],
                'work_date'=>sprintf('2026-11-%02d',$day),
                'day_type'=>'workday','planned_minutes'=>480,'revision'=>1,
            ]);
        }
        DB::table('leave_requests')->insert([
            'id'=>(string)Str::uuid(),'company_id'=>$fx['company'],
            'employee_id'=>$fx['employee'],'leave_type_id'=>$fx['type'],
            'planned_started_on'=>'2026-11-02','planned_ended_on'=>'2026-11-06',
            'planned_days'=>5,'status'=>'submitted','revision'=>1,
            'created_by_user_id'=>$fx['hr']->id,
        ]);
        $this->actingAs($fx['admin'])->putJson($this->url($fx),$this->payload())
            ->assertOk()->assertJsonPath('data.balance_method','working_days');
        $this->assertDatabaseHas('leave_types',['id'=>$fx['type'],'revision'=>2]);
    }
}
