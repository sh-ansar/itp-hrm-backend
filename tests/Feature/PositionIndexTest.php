<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PositionIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_positions_read_is_company_scoped_and_permissioned(): void
    {
        $a=(string) Str::uuid();
        $b=(string) Str::uuid();
        DB::table('companies')->insert([
            ['id'=>$a,'name'=>'A'], ['id'=>$b,'name'=>'B'],
        ]);
        DB::table('positions')->insert([
            ['id'=>(string)Str::uuid(),'company_id'=>$a,'name'=>'Engineer'],
            ['id'=>(string)Str::uuid(),'company_id'=>$b,'name'=>'Private Position'],
        ]);
        $user=User::factory()->create();
        $endpoint="/api/v1/companies/{$a}/positions";
        $this->getJson($endpoint)->assertUnauthorized();
        $this->actingAs($user)->getJson($endpoint)->assertForbidden();
        DB::table('company_user_roles')->insert(['company_id'=>$a,'user_id'=>$user->id,'role_code'=>'hr_specialist']);
        $this->actingAs($user)->getJson($endpoint)->assertOk()
            ->assertJsonPath('meta.total',1)
            ->assertJsonPath('data.0.name','Engineer')
            ->assertJsonMissing(['name'=>'Private Position']);
        $this->actingAs($user)->getJson("/api/v1/companies/{$b}/positions")->assertForbidden();
        $this->actingAs($user)->getJson($endpoint.'?per_page=101')->assertUnprocessable();
    }

    public function test_managers_and_employee_roles_cannot_browse_global_company_positions(): void
    {
        $company=(string)Str::uuid();
        DB::table('companies')->insert(['id'=>$company,'name'=>'A']);
        $user=User::factory()->create();
        DB::table('company_user_roles')->insert(['company_id'=>$company,'user_id'=>$user->id,'role_code'=>'department_manager']);
        $url="/api/v1/companies/{$company}/positions";
        $this->actingAs($user)->getJson($url)->assertForbidden();
        DB::table('company_user_roles')->where('user_id',$user->id)->update(['role_code'=>'employee']);
        $this->actingAs($user)->getJson($url)->assertForbidden();
    }
}
