<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeeBonusWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config()->set('hrm.bonus_writes_enabled', true);
        $company = (string) Str::uuid();
        $outside = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $otherEmployee = (string) Str::uuid();
        DB::table('companies')->insert([
            ['id' => $company, 'name' => 'HR Test Company'],
            ['id' => $outside, 'name' => 'Other Company'],
        ]);
        DB::table('employees')->insert([
            ['id' => $employee, 'company_id' => $company, 'personnel_number' => 'B-1', 'first_name' => 'Test', 'last_name' => 'Bonus'],
            ['id' => $otherEmployee, 'company_id' => $outside, 'personnel_number' => 'B-2', 'first_name' => 'Other', 'last_name' => 'Employee'],
        ]);
        $hr = User::factory()->create();
        $admin = User::factory()->create();
        $adminSecond = User::factory()->create();
        $staff = User::factory()->create();
        $stranger = User::factory()->create();
        foreach ([[$hr, 'hr_specialist'], [$admin, 'company_admin'], [$adminSecond, 'company_admin'], [$staff, 'employee']] as [$actor, $role]) {
            DB::table('company_user_roles')->insert([
                'company_id' => $company, 'user_id' => $actor->id, 'role_code' => $role,
            ]);
        }

        return compact('company', 'outside', 'employee', 'otherEmployee', 'hr', 'admin', 'adminSecond', 'staff', 'stranger');
    }

    private function payload(string $employee, array $changes = []): array
    {
        return array_replace([
            'employee_id' => $employee,
            'bonus_kind' => 'performance',
            'award_on' => '2026-10-15',
            'amount' => '27500.50',
            'currency' => 'KZT',
            'basis_reference' => 'Memo HR-2026/15',
            'note' => 'Approved performance criteria',
            'request_key' => (string) Str::uuid(),
        ], $changes);
    }

    public function test_bonus_api_fail_closed_and_requires_authentication(): void
    {
        $actor = User::factory()->create();
        $company = (string) Str::uuid();
        $base = "/api/v1/companies/{$company}/bonuses";

        $this->actingAs($actor)->getJson($base)->assertNotFound();
        $this->actingAs($actor)->postJson($base, [])->assertNotFound();
        config()->set('hrm.bonus_writes_enabled', true);

        $this->app['auth']->forgetGuards();
        $this->getJson($base)->assertUnauthorized();
        $this->postJson($base, [])->assertUnauthorized();
    }

    public function test_hr_creates_submits_and_another_admin_approves_without_payroll_posting(): void
    {
        extract($this->fixture());
        $base = "/api/v1/companies/{$company}/bonuses";
        $payload = $this->payload($employee);

        $created = $this->actingAs($hr)->postJson($base, $payload)
            ->assertCreated()->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.amount', '27500.50')
            ->assertJsonPath('data.revision', 1)->json('data');
        $id = $created['id'];

        // A network retry returns the original record and does not append an audit event.
        $this->actingAs($hr)->postJson($base, $payload)
            ->assertCreated()->assertJsonPath('data.id', $id);
        $this->actingAs($hr)->postJson($base, array_replace($payload, ['amount' => '10.00']))
            ->assertStatus(409);

        $this->actingAs($hr)->putJson($base.'/'.$id, array_replace(
            $this->payload($employee), ['amount' => '30000', 'revision' => 1, 'request_key' => (string) Str::uuid()],
        ))->assertUnprocessable(); // PUT must never accept a create-only request key.

        $changed = array_replace($payload, [
            'amount' => '30000', 'revision' => 1,
        ]);
        unset($changed['request_key']);
        $this->actingAs($hr)->putJson($base.'/'.$id, $changed)
            ->assertOk()->assertJsonPath('data.amount', '30000.00')
            ->assertJsonPath('data.revision', 2);
        $this->actingAs($hr)->putJson($base.'/'.$id, $changed)->assertStatus(409);

        $this->actingAs($hr)->postJson($base.'/'.$id.'/submit', ['revision' => 2])
            ->assertOk()->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.revision', 3);

        $this->actingAs($hr)->postJson($base.'/'.$id.'/decision', [
            'revision' => 3, 'decision' => 'approved',
        ])->assertForbidden();
        $this->actingAs($admin)->postJson($base.'/'.$id.'/decision', [
            'revision' => 3, 'decision' => 'approved',
        ])->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.revision', 4)
            ->assertJsonPath('data.amount', '30000.00');

        $this->actingAs($admin)->postJson($base.'/'.$id.'/decision', [
            'revision' => 3, 'decision' => 'approved',
        ])->assertStatus(409);
        $this->actingAs($hr)->postJson($base.'/'.$id.'/cancel', ['revision' => 4])->assertStatus(409);

        $this->assertDatabaseCount('employee_bonuses', 1);
        $this->assertDatabaseHas('employee_bonuses', ['id' => $id, 'status' => 'approved', 'revision' => 4]);
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'action' => 'compensation.bonus_approved',
        ]);
        $this->assertEquals(4, DB::table('hr_audit_events')
            ->where('company_id', $company)->where('action', 'like', 'compensation.bonus_%')->count());

        $this->actingAs($hr)->getJson($base.'?status=approved')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.can_approve', false)
            ->assertJsonPath('meta.payroll_posting_enabled', false);
        $this->actingAs($admin)->getJson($base)->assertOk()
            ->assertJsonPath('meta.can_approve', true);
    }

    public function test_permissions_company_boundaries_and_self_approval_are_enforced(): void
    {
        extract($this->fixture());
        $base = "/api/v1/companies/{$company}/bonuses";
        $payload = $this->payload($employee);

        $this->actingAs($staff)->getJson($base)->assertForbidden();
        $this->actingAs($staff)->postJson($base, $payload)->assertForbidden();
        $this->actingAs($stranger)->getJson($base)->assertForbidden();
        $this->actingAs($stranger)->postJson($base, $payload)->assertForbidden();

        $this->actingAs($hr)->postJson($base, $this->payload($otherEmployee))
            ->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->actingAs($hr)->postJson("/api/v1/companies/{$outside}/bonuses", $payload)
            ->assertForbidden();

        $created = $this->actingAs($admin)->postJson($base, $payload)->assertCreated()->json('data');
        $id = $created['id'];
        $this->actingAs($admin)->postJson($base.'/'.$id.'/submit', ['revision' => 1])->assertOk();
        $this->actingAs($admin)->postJson($base.'/'.$id.'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertForbidden();
        $this->actingAs($adminSecond)->postJson($base.'/'.$id.'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertOk();

        $this->actingAs($hr)->getJson("/api/v1/companies/{$outside}/bonuses")->assertForbidden();
        $this->actingAs($staff)->postJson($base.'/'.$id.'/cancel', ['revision' => 3])->assertForbidden();
        $this->actingAs($adminSecond)->getJson($base.'?employee_id='.$otherEmployee)
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_validation_rejection_and_cancellation_are_audited(): void
    {
        extract($this->fixture());
        $base = "/api/v1/companies/{$company}/bonuses";
        foreach ([
            ['amount' => '0'],
            ['amount' => '-10'],
            ['amount' => '3.999'],
            ['amount' => '1234567890123.00'],
            ['currency' => 'kzt'],
            ['bonus_kind' => 'other_unknown'],
            ['award_on' => '2026-99-99'],
            ['basis_reference' => ''],
        ] as $bad) {
            $this->actingAs($hr)->postJson($base, $this->payload($employee, $bad))->assertUnprocessable();
        }
        $this->assertDatabaseCount('employee_bonuses', 0);

        $request = $this->actingAs($hr)->postJson($base, $this->payload($employee))
            ->assertCreated()->json('data');
        $id = $request['id'];
        $this->actingAs($hr)->postJson($base.'/'.$id.'/decision', [
            'revision' => 1, 'decision' => 'rejected', 'comment' => 'No',
        ])->assertForbidden();
        $this->actingAs($hr)->postJson($base.'/'.$id.'/submit', ['revision' => 1])->assertOk();
        $this->actingAs($admin)->postJson($base.'/'.$id.'/decision', [
            'revision' => 2, 'decision' => 'rejected', 'comment' => '',
        ])->assertUnprocessable();
        $this->actingAs($admin)->postJson($base.'/'.$id.'/decision', [
            'revision' => 2, 'decision' => 'rejected', 'comment' => 'Insufficient basis',
        ])->assertOk()->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.note', 'Approved performance criteria')
            ->assertJsonPath('data.decision_comment', 'Insufficient basis');

        $cancel = $this->actingAs($hr)->postJson($base, $this->payload($employee))
            ->assertCreated()->json('data');
        $this->actingAs($hr)->postJson($base.'/'.$cancel['id'].'/cancel', [
            'revision' => 1,
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->actingAs($hr)->postJson($base.'/'.$cancel['id'].'/submit', ['revision' => 2])->assertStatus(409);
        $this->assertDatabaseHas('hr_audit_events', ['action' => 'compensation.bonus_rejected']);
        $this->assertDatabaseHas('hr_audit_events', ['action' => 'compensation.bonus_cancelled']);
        $this->assertDatabaseCount('employee_bonuses', 2);
    }
}
