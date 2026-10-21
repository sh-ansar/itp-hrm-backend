<?php

namespace Tests\Feature;

use App\Domain\Identity\CompanyRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompanyDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company=(string)Str::uuid();
        DB::table('companies')->insert(['id'=>$company,'name'=>'Synthetic']);
        $admin=User::factory()->create();
        DB::table('company_user_roles')->insert([
            'user_id'=>$admin->id,'company_id'=>$company,'role_code'=>CompanyRole::CompanyAdmin->value,
        ]);
        return [$admin,$company];
    }

    public function test_admin_can_create_list_update_and_delete_directory_entry_with_audit(): void
    {
        config()->set('hrm.directory_writes_enabled',true);
        [$admin,$company]=$this->fixture();
        $base="/api/v1/companies/{$company}/directories/tariff-types";

        $created=$this->actingAs($admin)->postJson($base,[
            'code'=>'TRF-01','name'=>'Base tariff','category'=>'Tariff','unit'=>'KZT','value'=>'1000','status'=>'active',
        ])->assertCreated()->json('data');

        $this->actingAs($admin)->getJson($base)->assertOk()
            ->assertJsonPath('meta.writes_enabled',true)
            ->assertJsonPath('data.0.code','TRF-01');

        $this->actingAs($admin)->putJson($base.'/'.$created['id'],[
            'code'=>'TRF-01','name'=>'Updated tariff','category'=>'Tariff','unit'=>'KZT','value'=>'1200',
            'status'=>'active','revision'=>1,
        ])->assertOk()->assertJsonPath('data.revision',2);

        $this->actingAs($admin)->deleteJson($base.'/'.$created['id'],['revision'=>2])->assertNoContent();
        $this->assertDatabaseCount('company_directory_entries',0);
        $this->assertSame(3,DB::table('hr_audit_events')->where('company_id',$company)->where('action','like','directory.%')->count());
    }

    public function test_writes_are_fail_closed_but_reads_remain_available(): void
    {
        config()->set('hrm.directory_writes_enabled',false);
        [$admin,$company]=$this->fixture();
        $base="/api/v1/companies/{$company}/directories/leave-types";

        $this->actingAs($admin)->getJson($base)->assertOk()->assertJsonPath('meta.writes_enabled',false);
        $this->actingAs($admin)->postJson($base,[
            'code'=>'A','name'=>'Annual','status'=>'active',
        ])->assertNotFound();
    }

    public function test_unknown_group_and_non_hr_access_are_rejected(): void
    {
        config()->set('hrm.directory_writes_enabled',true);
        [$admin,$company]=$this->fixture();
        $employee=User::factory()->create();
        DB::table('company_user_roles')->insert([
            'user_id'=>$employee->id,'company_id'=>$company,'role_code'=>CompanyRole::Employee->value,
        ]);

        $this->actingAs($admin)->getJson("/api/v1/companies/{$company}/directories/not-real")->assertNotFound();
        $this->actingAs($employee)->getJson("/api/v1/companies/{$company}/directories/tariff-types")->assertForbidden();
    }

    public function test_tariff_tree_rejects_cross_company_parents_cycles_and_parent_deletion(): void
    {
        config()->set('hrm.directory_writes_enabled', true);
        [$admin, $company] = $this->fixture();
        [$other, $otherCompany] = $this->fixture();
        $base = "/api/v1/companies/{$company}/directories/tariff-types";
        $otherBase = "/api/v1/companies/{$otherCompany}/directories/tariff-types";
        $payload = fn (string $code, ?string $parent = null) => [
            'code' => $code, 'name' => $code, 'parent_id' => $parent, 'status' => 'active',
        ];
        $root = $this->actingAs($admin)->postJson($base, $payload('ROOT'))
            ->assertCreated()->json('data');
        $child = $this->actingAs($admin)->postJson($base, $payload('CHILD', $root['id']))
            ->assertCreated()->assertJsonPath('data.parent_id', $root['id'])->json('data');
        $foreign = $this->actingAs($other)->postJson($otherBase, $payload('FOREIGN'))
            ->assertCreated()->json('data');

        $this->actingAs($admin)->postJson($base, $payload('CROSS', $foreign['id']))
            ->assertStatus(422);
        $this->actingAs($admin)->postJson($base, $payload('SELF', (string) Str::uuid()))
            ->assertStatus(422);
        $this->actingAs($admin)->putJson($base.'/'.$root['id'], [
            ...$payload('ROOT', $child['id']), 'revision' => 1,
        ])->assertStatus(422);
        $this->actingAs($admin)->deleteJson($base.'/'.$root['id'], ['revision' => 1])
            ->assertStatus(409);
        $this->actingAs($admin)->deleteJson($base.'/'.$child['id'], ['revision' => 1])
            ->assertNoContent();
        $this->actingAs($admin)->deleteJson($base.'/'.$root['id'], ['revision' => 1])
            ->assertNoContent();
        $this->assertDatabaseHas('company_directory_entries', ['id' => $foreign['id']]);
    }

}
