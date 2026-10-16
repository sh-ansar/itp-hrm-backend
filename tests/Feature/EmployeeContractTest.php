<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeeContractTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function fixture(string $role = 'hr_specialist'): array
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-16 12:00:00'));

        $user = User::factory()->create();
        $company = (string) Str::uuid();
        $employee = (string) Str::uuid();

        DB::table('companies')->insert(['id' => $company, 'name' => 'Contract Company']);
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id,
            'company_id' => $company,
            'role_code' => $role,
        ]);
        DB::table('employees')->insert([
            'id' => $employee,
            'company_id' => $company,
            'personnel_number' => 'CTR-001',
            'last_name' => 'Contract',
            'first_name' => 'Employee',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $company, $employee];
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'contract_number' => 'TD-2026-001',
            'contract_type' => 'fixed_term',
            'employment_type' => 'full_time',
            'signed_on' => '2026-01-10',
            'valid_from' => '2026-01-15',
            'valid_to' => '2026-10-30',
            'probation_until' => '2026-04-15',
            'basis_reference' => 'Приказ № 1',
        ], $overrides);
    }

    public function test_contract_writes_are_fail_closed(): void
    {
        [$user, $company, $employee] = $this->fixture();

        $this->actingAs($user)
            ->postJson("/api/v1/companies/{$company}/employees/{$employee}/contracts", $this->payload())
            ->assertNotFound();

        $this->assertDatabaseCount('employee_contracts', 0);
    }

    public function test_hr_can_create_read_and_update_contract_with_revision(): void
    {
        config()->set('hrm.personnel_writes_enabled', true);
        [$user, $company, $employee] = $this->fixture();

        $create = $this->actingAs($user)
            ->postJson("/api/v1/companies/{$company}/employees/{$employee}/contracts", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.contract_type', 'fixed_term')
            ->assertJsonPath('data.valid_to', '2026-10-30')
            ->assertJsonPath('data.revision', 1);

        $contract = $create->json('data.id');

        $this->actingAs($user)
            ->getJson("/api/v1/companies/{$company}/employees/{$employee}/contracts")
            ->assertOk()
            ->assertJsonPath('data.0.id', $contract)
            ->assertJsonPath('data.0.contract_number', 'TD-2026-001')
            ->assertJsonPath('meta.writes_enabled', true);

        $this->actingAs($user)
            ->putJson("/api/v1/companies/{$company}/employees/{$employee}/contracts/{$contract}", [
                ...$this->payload([
                    'contract_number' => 'TD-2026-001A',
                    'valid_to' => '2026-11-10',
                ]),
                'revision' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.contract_number', 'TD-2026-001A')
            ->assertJsonPath('data.revision', 2);

        $this->assertDatabaseHas('employee_contracts', [
            'id' => $contract,
            'company_id' => $company,
            'employee_id' => $employee,
            'contract_number' => 'TD-2026-001A',
            'revision' => 2,
        ]);
        $this->assertSame(2, DB::table('hr_audit_events')
            ->where('company_id', $company)
            ->whereIn('action', ['personnel.contract_created', 'personnel.contract_updated'])
            ->count());
    }

    public function test_contract_periods_cannot_overlap(): void
    {
        config()->set('hrm.personnel_writes_enabled', true);
        [$user, $company, $employee] = $this->fixture();

        $this->actingAs($user)
            ->postJson("/api/v1/companies/{$company}/employees/{$employee}/contracts", $this->payload())
            ->assertCreated();

        $this->actingAs($user)
            ->postJson("/api/v1/companies/{$company}/employees/{$employee}/contracts", $this->payload([
                'contract_number' => 'TD-2026-002',
                'valid_from' => '2026-10-01',
                'valid_to' => '2026-12-31',
                'probation_until' => null,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('valid_from');

        $this->assertDatabaseCount('employee_contracts', 1);
    }

    public function test_contract_type_semantics_are_enforced(): void
    {
        config()->set('hrm.personnel_writes_enabled', true);
        [$user, $company, $employee] = $this->fixture();

        $this->actingAs($user)
            ->postJson("/api/v1/companies/{$company}/employees/{$employee}/contracts", $this->payload([
                'contract_type' => 'indefinite',
                'valid_to' => '2026-12-31',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('valid_to');

        $this->actingAs($user)
            ->postJson("/api/v1/companies/{$company}/employees/{$employee}/contracts", $this->payload([
                'contract_number' => 'TD-2026-002',
                'contract_type' => 'fixed_term',
                'valid_to' => null,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('valid_to');
    }

    public function test_registry_exposes_current_contract_and_30_day_expiry_summary_for_hr(): void
    {
        config()->set('hrm.personnel_writes_enabled', true);
        [$user, $company, $employee] = $this->fixture();

        $this->actingAs($user)
            ->postJson("/api/v1/companies/{$company}/employees/{$employee}/contracts", $this->payload())
            ->assertCreated();

        $response = $this->actingAs($user)
            ->getJson("/api/v1/companies/{$company}/employees?status=all&per_page=25")
            ->assertOk()
            ->assertJsonPath('data.0.contract_type', 'fixed_term')
            ->assertJsonPath('data.0.contract_valid_to', '2026-10-30')
            ->assertJsonPath('meta.registry_summary.expiring_contracts', 1)
            ->assertJsonPath('meta.registry_summary.expiring_contracts_days', 30);

        $this->assertSame($employee, $response->json('data.0.id'));
    }

    public function test_expiry_windows_count_only_authorized_current_contracts(): void
    {
        config()->set('hrm.personnel_writes_enabled', true);
        [$user, $company, $employee] = $this->fixture();
        $this->actingAs($user)
            ->postJson("/api/v1/companies/{$company}/employees/{$employee}/contracts", $this->payload([
                'valid_to' => now()->addDays(45)->toDateString(),
            ]))->assertCreated();

        foreach ([30 => 0, 60 => 1, 90 => 1] as $days => $count) {
            $this->getJson("/api/v1/companies/{$company}/employees?contract_expiry_days={$days}")
                ->assertOk()
                ->assertJsonPath('meta.registry_summary.expiring_contracts', $count)
                ->assertJsonPath('meta.registry_summary.expiring_contracts_days', $days);
        }
        // The optional query preserves the existing default contract.
        $this->getJson("/api/v1/companies/{$company}/employees")
            ->assertJsonPath('meta.registry_summary.expiring_contracts', 0)
            ->assertJsonPath('meta.registry_summary.expiring_contracts_days', 30);

        foreach ([0, 31, 365, 'invalid'] as $invalid) {
            $this->getJson("/api/v1/companies/{$company}/employees?contract_expiry_days={$invalid}")
                ->assertUnprocessable()->assertJsonValidationErrors('contract_expiry_days');
        }
        $otherCompany = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $otherCompany, 'name' => 'Other Company']);
        $this->getJson("/api/v1/companies/{$otherCompany}/employees?contract_expiry_days=90")
            ->assertForbidden();

        // Manager sees no legal contract aggregate, even with a wider window.
        DB::table('company_user_roles')->where('user_id', $user->id)
            ->where('company_id', $company)->update(['role_code' => 'department_manager']);
        $this->getJson("/api/v1/companies/{$company}/employees?contract_expiry_days=90")
            ->assertOk()->assertJsonPath('meta.registry_summary.expiring_contracts', null);
    }

    public function test_department_manager_cannot_read_legal_contract_details(): void
    {
        config()->set('hrm.personnel_writes_enabled', true);
        [$manager, $company, $employee] = $this->fixture('department_manager');

        $this->actingAs($manager)
            ->getJson("/api/v1/companies/{$company}/employees/{$employee}/contracts")
            ->assertForbidden();
    }
}
