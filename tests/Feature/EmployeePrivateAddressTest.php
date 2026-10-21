<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeePrivateAddressTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $user = User::factory()->create();
        DB::table('companies')->insert(['id' => $company, 'name' => 'QA Tenant']);
        DB::table('employees')->insert([
            'id' => $employee, 'company_id' => $company,
            'personnel_number' => 'ADDR-QA-1', 'last_name' => 'Test', 'first_name' => 'Employee',
        ]);
        DB::table('company_user_roles')->insert([
            'company_id' => $company, 'user_id' => $user->id, 'role_code' => 'hr_specialist',
        ]);
        return [$company, $employee, $user, "/api/v1/companies/{$company}/employees/{$employee}"];
    }

    private function address(array $override = []): array
    {
        return array_replace([
            'kind' => 'residence',
            'address_line' => 'Example street 42, synthetic town',
            'entrance' => 'B',
            'floor' => '4',
            'apartment' => '27',
            'is_primary' => true,
        ], $override);
    }

    public function test_addresses_default_to_empty_and_writes_remain_flagged_off(): void
    {
        [$company, $employee, $hr, $base] = $this->fixture();
        $this->actingAs($hr)->getJson($base)->assertOk()
            ->assertJsonCount(0, 'private_profile.addresses')
            ->assertJsonPath('meta.private_addresses_truncated', false);
        $this->actingAs($hr)->postJson($base.'/private-records/address', $this->address())
            ->assertNotFound();
    }

    public function test_scoped_address_versions_primary_switch_and_archive_are_atomic(): void
    {
        [$company, $employee, $hr, $base] = $this->fixture();
        config()->set('hrm.personnel_writes_enabled', true);
        $url = $base.'/private-records/address';

        $first = $this->actingAs($hr)->postJson($url, $this->address())
            ->assertCreated()->assertJsonPath('data.revision', 1)->json('data.id');
        $second = $this->actingAs($hr)->postJson($url, $this->address([
            'kind' => 'registration',
            'address_line' => 'Synthetic registration avenue 17',
            'is_primary' => true,
        ]))->assertCreated()->json('data.id');

        $this->actingAs($hr)->getJson($base)->assertOk()
            ->assertJsonCount(2, 'private_profile.addresses')
            ->assertJsonPath('private_profile.addresses.0.id', $second)
            ->assertJsonPath('private_profile.addresses.0.is_primary', true);
        $this->assertDatabaseHas('employee_private_addresses', [
            'id' => $first, 'is_primary' => false, 'revision' => 2,
        ]);

        $this->actingAs($hr)->putJson($url.'/'.$first, [
            ...$this->address(), 'revision' => 1,
        ])->assertStatus(409);
        $this->actingAs($hr)->putJson($url.'/'.$first, [
            ...$this->address(['apartment' => '44']), 'revision' => 2,
        ])->assertOk()->assertJsonPath('data.revision', 3);
        $this->assertDatabaseHas('employee_private_addresses', [
            'id' => $first, 'is_primary' => true, 'apartment' => '44', 'revision' => 3,
        ]);
        $this->assertDatabaseHas('employee_private_addresses', [
            'id' => $second, 'is_primary' => false, 'revision' => 2,
        ]);
        $this->actingAs($hr)->deleteJson($url.'/'.$first, ['revision' => 2])
            ->assertStatus(409);
        $this->actingAs($hr)->deleteJson($url.'/'.$first, ['revision' => 3])
            ->assertOk()->assertJsonPath('data.archived', true);
        $this->actingAs($hr)->getJson($base)->assertOk()
            ->assertJsonCount(1, 'private_profile.addresses')
            ->assertJsonPath('private_profile.addresses.0.id', $second);
        $this->assertDatabaseCount('employee_private_addresses', 2);
        $this->assertNotNull(DB::table('employee_private_addresses')->where('id', $first)->value('archived_at'));
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'action' => 'personnel.private_record_archived',
        ]);
    }

    public function test_bad_address_payloads_and_cross_tenant_writes_are_rejected(): void
    {
        [$company, $employee, $hr, $base] = $this->fixture();
        config()->set('hrm.personnel_writes_enabled', true);
        $url = $base.'/private-records/address';
        $this->actingAs($hr)->postJson($url, $this->address(['kind' => 'unknown']))
            ->assertUnprocessable();
        $this->actingAs($hr)->postJson($url, $this->address(['address_line' => '']))
            ->assertUnprocessable();
        $this->actingAs($hr)->postJson($url, [
            ...$this->address(), 'company_id' => $company,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('employee_private_addresses', 0);

        $other = User::factory()->create();
        $foreign = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $foreign, 'name' => 'Another Tenant']);
        DB::table('company_user_roles')->insert([
            'company_id' => $foreign, 'user_id' => $other->id, 'role_code' => 'company_admin',
        ]);
        $this->actingAs($other)->postJson($url, $this->address())->assertForbidden();
        $this->actingAs($other)->getJson($base)->assertForbidden();
        $this->assertDatabaseCount('employee_private_addresses', 0);
    }
}
