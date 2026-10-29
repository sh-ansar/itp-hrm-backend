<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Business accounting is deliberately explicit. A leave_type's annual_days
 * is not an employee entitlement, and protected leave has no annual ledger.
 */
final class LeaveBalanceLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config()->set([
            'hrm.leave_writes_enabled' => true,
            'hrm.leave_balance_writes_enabled' => false,
            'hrm.leave_balance_enforcement_enabled' => false,
        ]);
        $admin = User::factory()->create();
        $hr = User::factory()->create();
        $staff = User::factory()->create();
        $other = User::factory()->create();
        $company = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $otherEmployee = (string) Str::uuid();
        $ordinary = (string) Str::uuid();
        $protected = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $company, 'name' => 'Synthetic balance company']);
        DB::table('employees')->insert([
            ['id' => $employee, 'company_id' => $company, 'personnel_number' => 'BAL-001',
                'first_name' => 'Synthetic', 'last_name' => 'Worker'],
            ['id' => $otherEmployee, 'company_id' => $company, 'personnel_number' => 'BAL-002',
                'first_name' => 'Other', 'last_name' => 'Worker'],
        ]);
        DB::table('leave_types')->insert([
            ['id' => $ordinary, 'company_id' => $company, 'code' => 'annual',
                'name' => 'Annual paid', 'annual_days' => 24, 'balance_method' => 'calendar_days',
                'category' => 'ordinary', 'revision' => 1],
            ['id' => $protected, 'company_id' => $company, 'code' => 'parental',
                'name' => 'Protected', 'annual_days' => null, 'balance_method' => 'calendar_days',
                'category' => 'maternity', 'revision' => 1],
        ]);
        foreach ([[$admin, 'company_admin'], [$hr, 'hr_specialist'],
            [$staff, 'employee'], [$other, 'employee']] as [$user, $role]) {
            DB::table('company_user_roles')->insert([
                'company_id' => $company, 'user_id' => $user->id, 'role_code' => $role,
            ]);
        }
        DB::table('employee_user_links')->insert([
            'company_id' => $company, 'user_id' => $staff->id,
            'employee_id' => $employee, 'revision' => 1,
        ]);
        $base = "/api/v1/companies/{$company}";
        return compact('admin', 'hr', 'staff', 'other', 'company',
            'employee', 'otherEmployee', 'ordinary', 'protected', 'base');
    }

    private function open(User $admin, string $base, string $employee, string $type,
        int $year, int $days): array
    {
        return $this->actingAs($admin)->postJson($base.'/leave-balances/periods', [
            'employee_id' => $employee, 'leave_type_id' => $type,
            'year' => $year, 'granted_days' => $days,
            'reason' => 'Reviewed company entitlement decision',
        ])->assertCreated()->json('data');
    }

    private function submitted(User $hr, string $base, string $employee, string $type,
        string $from, string $to): array
    {
        $data = $this->actingAs($hr)->postJson($base.'/leave-requests', [
            'employee_id' => $employee, 'leave_type_id' => $type,
            'planned_started_on' => $from, 'planned_ended_on' => $to, 'note' => null,
        ])->assertCreated()->json('data');
        $this->postJson($base.'/leave-requests/'.$data['id'].'/submit', [
            'revision' => 1,
        ])->assertOk();
        return $data;
    }

    public function test_paged_registry_is_hr_scoped_and_separates_unverified_plan_from_actual_usage(): void
    {
        extract($this->fixture());
        config()->set('hrm.leave_balance_writes_enabled', true);
        $this->open($admin, $base, $employee, $ordinary, 2026, 24);
        $url = $base.'/leave-balances/registry?year=2026&per_page=20';
        $this->actingAs($staff)->getJson($url)->assertForbidden();
        $this->actingAs($other)->getJson($url)->assertForbidden();
        $this->actingAs($hr)->getJson($url.'&unexpected=1')->assertUnprocessable();
        $this->getJson($url.'&department_id=bad')->assertUnprocessable();
        $this->getJson($url.'&q=BAL-001')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.balances.0.granted_days', 24)
            ->assertJsonPath('data.0.balances.0.booked_days', 0)
            ->assertJsonPath('data.0.balances.0.actual_verified_days', 0)
            ->assertJsonPath('data.0.balances.0.unspent_entitlement_days', 24);
        // An approved planned interval is not itself confirmed use.
        $request = $this->submitted($hr, $base, $employee, $ordinary, '2026-09-01', '2026-09-10');
        $this->actingAs($admin)->postJson($base.'/leave-requests/'.$request['id'].'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertOk();
        $this->actingAs($hr)->getJson($url.'&q=BAL-001')
            ->assertJsonPath('data.0.balances.0.booked_days', 10)
            ->assertJsonPath('data.0.balances.0.available_days', 14)
            ->assertJsonPath('data.0.balances.0.actual_verified_days', null)
            ->assertJsonPath('data.0.balances.0.unspent_entitlement_days', null)
            ->assertJsonPath('data.0.balances.0.actual_unverified_records', 1);
        // Only the explicitly verified actual interval is debited in factual view.
        DB::table('employee_leaves')->where('employee_id', $employee)->update([
            'actual_started_on' => '2026-09-01',
            'actual_ended_on' => '2026-09-06',
            'actual_days' => 6,
        ]);
        $this->getJson($url.'&q=BAL-001')
            ->assertJsonPath('data.0.balances.0.actual_verified_days', 6)
            ->assertJsonPath('data.0.balances.0.unspent_entitlement_days', 18)
            ->assertJsonPath('data.0.balances.0.booked_days', 10);
        // A different company must never be able to read those employee snapshots.
        $foreign = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $foreign, 'name' => 'Foreign organization']);
        $this->getJson('/api/v1/companies/'.$foreign.'/leave-balances/registry?year=2026')
            ->assertForbidden();
    }

    public function test_future_approved_plan_does_not_spend_unverified_entitlement_or_invent_a_grant(): void
    {
        extract($this->fixture());
        config()->set('hrm.leave_balance_writes_enabled', true);
        $this->open($admin, $base, $employee, $ordinary, 2027, 24);
        $request = $this->submitted($hr, $base, $employee, $ordinary, '2027-03-01', '2027-03-14');
        $this->actingAs($admin)->postJson($base.'/leave-requests/'.$request['id'].'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertOk();
        $this->actingAs($hr)->getJson($base.'/leave-balances/registry?year=2027&q=BAL-001')
            ->assertOk()->assertJsonPath('data.0.balances.0.booked_days', 14)
            ->assertJsonPath('data.0.balances.0.actual_verified_days', 0)
            ->assertJsonPath('data.0.balances.0.unspent_entitlement_days', 24)
            ->assertJsonPath('data.0.balances.0.available_days', 10);
        // Other employee with no opened period has unknown, not a fake 24-day balance.
        $this->getJson($base.'/leave-balances/registry?year=2027&q=BAL-002')
            ->assertJsonPath('data.0.balances.0.granted_days', null)
            ->assertJsonPath('data.0.balances.0.unspent_entitlement_days', null);
    }

    public function test_audited_no_show_allows_full_unused_historical_carry_without_faking_fact(): void
    {
        extract($this->fixture());
        config()->set('hrm.leave_balance_writes_enabled', true);
        $this->open($admin, $base, $employee, $ordinary, 2025, 24);
        $this->open($admin, $base, $employee, $ordinary, 2026, 24);
        $planned = $this->submitted($hr, $base, $employee, $ordinary,
            '2025-09-01', '2025-09-14');
        $this->actingAs($admin)->postJson($base.'/leave-requests/'.$planned['id'].'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertOk();
        $leaveId = DB::table('employee_leaves')->where('employee_id',$employee)->value('id');
        $this->actingAs($hr)->getJson($base.'/leave-balances/registry?year=2025&q=BAL-001')
            ->assertJsonPath('data.0.balances.0.booked_days', 14)
            ->assertJsonPath('data.0.balances.0.actual_verified_days', null);
        $period2026 = DB::table('leave_balance_periods')
            ->where('employee_id',$employee)->where('year',2026)->first();
        $period2025 = DB::table('leave_balance_periods')
            ->where('employee_id',$employee)->where('year',2025)->first();
        $carry = $base.'/leave-balances/periods/'.$period2026->id.'/carryover';
        $input = ['revision' => $period2026->revision, 'source_period_id' => $period2025->id,
            'days' => 24, 'reason' => 'Reviewed unused 2025 annual balance'];
        $this->actingAs($admin)->postJson($carry, $input)->assertUnprocessable();

        $url = $base.'/leaves/'.$leaveId.'/actual';
        $payload = ['revision' => 1, 'state' => 'not_taken',
            'reason' => 'HR confirmed employee did not take the planned days'];
        $this->actingAs($staff)->putJson($url, $payload)->assertForbidden();
        $this->actingAs($hr)->putJson($url, [
            ...$payload, 'actual_started_on' => '2025-09-01',
        ])->assertUnprocessable();
        $this->putJson($url, [...$payload, 'revision' => 200])->assertConflict();
        $this->putJson($url, $payload)->assertOk()
            ->assertJsonPath('data.actual_review_state', 'not_taken')
            ->assertJsonPath('data.actual_days', 0)
            ->assertJsonPath('data.revision', 2);
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'action' => 'leave.actual_reviewed',
        ]);
        $this->getJson($base.'/leave-balances/registry?year=2025&q=BAL-001')
            ->assertOk()->assertJsonPath('data.0.balances.0.actual_verified_days', 0)
            ->assertJsonPath('data.0.balances.0.unspent_entitlement_days', 24)
            ->assertJsonPath('data.0.balances.0.booked_days', 14);

        $this->actingAs($admin)->postJson($carry, $input)->assertOk()
            ->assertJsonPath('data.carried_days', 24)
            ->assertJsonPath('data.available_days', 48);
        $this->assertDatabaseHas('leave_balance_periods', [
            'id' => $period2025->id,'closing_available_days' => 24,
        ]);
        $this->postJson($carry, [...$input, 'revision' => 2])->assertConflict();
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company,'action' => 'leave.balance_carried',
        ]);
    }

    public function test_actual_date_review_is_revisioned_scoped_and_never_future(): void
    {
        extract($this->fixture());
        $this->actingAs($hr);
        DB::table('employee_leaves')->insert([
            'id' => $id = (string) Str::uuid(),
            'employee_id' => $employee, 'leave_type_id' => $ordinary,
            'planned_started_on' => '2025-09-01','planned_ended_on' => '2025-09-14',
            'planned_days' => 14,'revision' => 1,
        ]);
        $url = $base.'/leaves/'.$id.'/actual';
        $this->putJson($url, ['revision' => 1,'state' => 'taken',
            'actual_started_on' => '2027-01-01','actual_ended_on' => '2027-01-10',
            'reason' => 'Confirmed reviewed leave period'])->assertUnprocessable();
        $this->putJson($url, ['revision' => 1,'state' => 'taken',
            'actual_started_on' => '2025-09-02','actual_ended_on' => '2025-09-09',
            'reason' => 'Confirmed actual leave dates by HR'])->assertOk()
            ->assertJsonPath('data.actual_days', 8)
            ->assertJsonPath('data.actual_review_state', 'taken');
        $this->putJson($url, ['revision' => 1,'state' => 'not_taken',
            'reason' => 'Old version cannot overwrite confirmed facts'])->assertConflict();
        $this->assertDatabaseHas('employee_leaves', [
            'id' => $id,'actual_days' => 8,
            'actual_started_on' => '2025-09-02',
            'actual_ended_on' => '2025-09-09',
        ]);
        $another = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $another,'name' => 'Other']);
        $this->putJson('/api/v1/companies/'.$another.'/leaves/'.$id.'/actual', [
            'revision' => 2,'state' => 'not_taken',
            'reason' => 'Cannot alter across company boundaries',
        ])->assertForbidden();
    }

    public function test_unconfigured_balance_is_null_not_a_fake_grant_and_read_is_tenant_safe(): void
    {
        extract($this->fixture());
        $mine = $base.'/me/leave-balances?year=2026';
        $own = $this->actingAs($staff)->getJson($mine)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.leave_type_id', $ordinary)
            ->assertJsonPath('data.0.reference_annual_days', 24)
            ->assertJsonPath('data.0.state', 'unconfigured')
            ->assertJsonPath('data.0.granted_days', null)
            ->assertJsonPath('data.0.available_days', null)
            ->assertJsonPath('data.0.booked_days', 0)
            ->assertJsonPath('meta.can_manage', false);
        $this->assertArrayNotHasKey('balance', $own->json('data.0'));
        $this->getJson($mine.'&employee_id='.$otherEmployee)->assertUnprocessable();
        $this->getJson($base.'/me/leave-balances?year=2026&company_id='.$company)->assertUnprocessable();
        $this->getJson($base.'/me/leave-balances?year=2026&unexpected=1')->assertUnprocessable();
        $this->getJson($base.'/leave-balances?year=2026&employee_id='.$employee)->assertForbidden();
        $this->actingAs($hr)->getJson($base.'/leave-balances?year=2026&employee_id='.$employee)
            ->assertOk()->assertJsonPath('data.0.available_days', null);
        $this->actingAs($other)->getJson($mine)->assertForbidden();
        DB::table('company_user_roles')->where('company_id', $company)
            ->where('user_id', $staff->id)->delete();
        $this->actingAs($staff)->getJson($mine)->assertForbidden();
    }

    public function test_explicit_entitlement_requires_two_feature_gates_admin_and_unique_employee_year(): void
    {
        extract($this->fixture());
        $url = $base.'/leave-balances/periods';
        $payload = ['employee_id' => $employee, 'leave_type_id' => $ordinary,
            'year' => 2026, 'granted_days' => 10, 'reason' => 'Approved HR entitlement'];
        $this->actingAs($admin)->postJson($url, $payload)->assertNotFound();
        config()->set('hrm.leave_balance_writes_enabled', true);
        $this->actingAs($hr)->postJson($url, $payload)->assertForbidden();
        $this->actingAs($staff)->postJson($url, $payload)->assertForbidden();
        $this->actingAs($admin)->postJson($url, $payload + ['company_id' => $company])->assertUnprocessable();
        $this->postJson($url, $payload + ['revision' => 0])->assertUnprocessable();
        $this->postJson($url, array_replace($payload, ['employee_id' => $otherEmployee]))->assertCreated();
        $this->postJson($url, $payload)->assertCreated()
            ->assertJsonPath('data.granted_days', 10)
            ->assertJsonPath('data.reference_annual_days', 24)
            ->assertJsonPath('data.available_days', 10)
            ->assertJsonPath('data.state', 'open');
        $this->postJson($url, $payload)->assertConflict();
        $this->postJson($url, array_replace($payload, [
            'leave_type_id' => $protected,
        ]))->assertUnprocessable();
        $foreign = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $foreign, 'name' => 'Foreign']);
        $this->postJson("/api/v1/companies/{$foreign}/leave-balances/periods", $payload)->assertForbidden();
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'action' => 'leave.balance_period_opened',
        ]);
        $this->assertDatabaseCount('leave_balance_entries', 2);
    }

    public function test_approvals_consume_exact_booked_days_and_never_double_count_submissions(): void
    {
        extract($this->fixture());
        config()->set('hrm.leave_balance_writes_enabled', true);
        $period = $this->open($admin, $base, $employee, $ordinary, 2026, 10);
        $first = $this->submitted($hr, $base, $employee, $ordinary, '2026-10-01', '2026-10-06');

        $this->actingAs($staff)->getJson($base.'/me/leave-balances?year=2026')
            ->assertJsonPath('data.0.pending_days', 6)
            ->assertJsonPath('data.0.available_days', 10);
        $this->actingAs($admin)->postJson($base.'/leave-requests/'.$first['id'].'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertOk();
        $this->actingAs($staff)->getJson($base.'/me/leave-balances?year=2026')
            ->assertJsonPath('data.0.booked_days', 6)
            ->assertJsonPath('data.0.pending_days', 0)
            ->assertJsonPath('data.0.available_days', 4);

        $second = $this->submitted($hr, $base, $employee, $ordinary, '2026-11-01', '2026-11-05');
        $this->actingAs($admin)->postJson($base.'/leave-requests/'.$second['id'].'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('employee_leaves', 1);
        $this->assertDatabaseHas('leave_requests', ['id' => $second['id'], 'status' => 'submitted']);
        $this->actingAs($staff)->getJson($base.'/me/leave-balances?year=2026')
            ->assertJsonPath('data.0.booked_days', 6)
            ->assertJsonPath('data.0.pending_days', 5)
            ->assertJsonPath('data.0.available_days', 4);

        $this->actingAs($admin)->postJson($base.'/leave-balances/periods/'.$period['id'].'/adjust', [
            'revision' => 1, 'delta_days' => 5, 'reason' => 'Additional grant approved',
        ])->assertOk()->assertJsonPath('data.available_days', 9);
        $this->postJson($base.'/leave-requests/'.$second['id'].'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertOk();
        $this->assertDatabaseCount('employee_leaves', 2);
        $this->actingAs($staff)->getJson($base.'/me/leave-balances?year=2026')
            ->assertJsonPath('data.0.booked_days', 11)
            ->assertJsonPath('data.0.pending_days', 0)
            ->assertJsonPath('data.0.available_days', 4);
    }

    public function test_adjustments_require_revision_reason_and_nonnegative_remaining_balance(): void
    {
        extract($this->fixture());
        config()->set('hrm.leave_balance_writes_enabled', true);
        $period = $this->open($admin, $base, $employee, $ordinary, 2026, 8);
        $url = $base.'/leave-balances/periods/'.$period['id'].'/adjust';
        $this->actingAs($hr)->postJson($url, [
            'revision' => 1, 'delta_days' => 2, 'reason' => 'Manager approved correction',
        ])->assertForbidden();
        $this->actingAs($admin)->postJson($url, [
            'revision' => 1, 'delta_days' => -9, 'reason' => 'Incorrect over-reduction',
        ])->assertUnprocessable();
        $this->postJson($url, [
            'revision' => 1, 'delta_days' => 0, 'reason' => 'Not a transaction',
        ])->assertUnprocessable();
        $this->postJson($url, [
            'revision' => 1, 'delta_days' => 3, 'reason' => 'Approved correction',
        ])->assertOk()->assertJsonPath('data.available_days', 11)
            ->assertJsonPath('data.revision', 2);
        $this->postJson($url, [
            'revision' => 1, 'delta_days' => -1, 'reason' => 'Stale correction',
        ])->assertConflict();
        $this->postJson($url, [
            'revision' => 2, 'delta_days' => -4, 'reason' => 'Approved correction',
        ])->assertOk()->assertJsonPath('data.available_days', 7);
        $this->assertDatabaseCount('leave_balance_entries', 3);
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'action' => 'leave.balance_adjusted',
        ]);
    }

    public function test_atomic_next_year_carryover_closes_source_prevents_second_transfer_and_freezes_approval(): void
    {
        extract($this->fixture());
        config()->set('hrm.leave_balance_writes_enabled', true);
        $source = $this->open($admin, $base, $employee, $ordinary, 2025, 14);
        $target = $this->open($admin, $base, $employee, $ordinary, 2026, 10);
        $first = $this->submitted($hr, $base, $employee, $ordinary, '2025-05-01', '2025-05-06');
        $this->actingAs($admin)->postJson($base.'/leave-requests/'.$first['id'].'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertOk();
        // Historical leave actual must be verified before carrying the 8 unused days.
        $record = DB::table('employee_leaves')->where('employee_id', $employee)->first();
        $this->actingAs($hr)->putJson($base.'/leaves/'.$record->id.'/actual', [
            'revision' => 1, 'state' => 'taken',
            'actual_started_on' => '2025-05-01', 'actual_ended_on' => '2025-05-06',
            'reason' => 'Verified historical leave attendance by HR',
        ])->assertOk()->assertJsonPath('data.actual_days', 6);
        $this->actingAs($admin);
        $url = $base.'/leave-balances/periods/'.$target['id'].'/carryover';
        $payload = [
            'revision' => 1, 'source_period_id' => $source['id'],
            'days' => 6, 'reason' => 'Approved annual close and transfer',
        ];
        $this->postJson($url, array_replace($payload, ['days' => 9]))->assertUnprocessable();
        $this->postJson($url, $payload)->assertOk()
            ->assertJsonPath('data.carried_days', 6)
            ->assertJsonPath('data.available_days', 16)
            ->assertJsonPath('data.revision', 2);
        $this->assertDatabaseHas('leave_balance_periods', [
            'id' => $source['id'], 'closing_available_days' => 8, 'revision' => 2,
        ]);
        $this->assertDatabaseHas('leave_balance_periods', [
            'id' => $target['id'], 'carry_from_period_id' => $source['id'], 'carried_days' => 6,
        ]);
        // A closed year is archived, not still spendable. The verified
        // closing amount is retained separately from transferred days.
        $this->actingAs($staff)->getJson($base.'/me/leave-balances?year=2025')
            ->assertJsonPath('data.0.state', 'closed')
            ->assertJsonPath('data.0.available_days', 0)
            ->assertJsonPath('data.0.closing_available_days', 8)
            ->assertJsonPath('data.0.carried_out_days', 6);
        $this->getJson($base.'/me/leave-balances?year=2026')
            ->assertJsonPath('data.0.available_days', 16)
            ->assertJsonPath('data.0.closing_available_days', null)
            ->assertJsonPath('data.0.carried_out_days', null);
        $this->actingAs($admin)->postJson($url, $payload)->assertConflict();
        $this->postJson($base.'/leave-balances/periods/'.$source['id'].'/adjust', [
            'revision' => 2, 'delta_days' => 1, 'reason' => 'Late adjustment forbidden',
        ])->assertConflict();
        $second = $this->submitted($hr, $base, $employee, $ordinary, '2025-10-01', '2025-10-02');
        $this->actingAs($admin)->postJson($base.'/leave-requests/'.$second['id'].'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertConflict();
        $this->assertDatabaseCount('employee_leaves', 1);
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'action' => 'leave.balance_carried',
        ]);
        $this->assertDatabaseCount('leave_balance_entries', 4);
    }

    public function test_enforcement_requires_explicit_grants_but_never_blocks_protected_leave(): void
    {
        extract($this->fixture());
        config()->set('hrm.leave_balance_enforcement_enabled', true);
        $first = $this->submitted($hr, $base, $employee, $ordinary, '2026-10-01', '2026-10-02');
        $this->actingAs($admin)->postJson($base.'/leave-requests/'.$first['id'].'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertUnprocessable();

        $protectedRequest = $this->submitted($hr, $base, $employee, $protected,
            '2026-12-01', '2026-12-05');
        $this->actingAs($admin)->postJson($base.'/leave-requests/'.$protectedRequest['id'].'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertOk();
        $this->actingAs($staff)->getJson($base.'/me/leave-balances?year=2026')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.leave_type_id', $ordinary);
    }

    public function test_cross_year_workday_balance_is_unknown_without_complete_calendar_not_zero(): void
    {
        extract($this->fixture());
        config()->set('hrm.leave_balance_writes_enabled', true);
        DB::table('leave_types')->where('id', $ordinary)->update(['balance_method' => 'working_days']);
        $this->open($admin, $base, $employee, $ordinary, 2026, 10);
        $this->open($admin, $base, $employee, $ordinary, 2027, 10);
        DB::table('employee_leaves')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $employee, 'leave_type_id' => $ordinary,
            'planned_started_on' => '2026-12-30', 'planned_ended_on' => '2027-01-03',
            'planned_days' => 3, 'revision' => 1,
        ]);
        $this->actingAs($staff)->getJson($base.'/me/leave-balances?year=2026')
            ->assertJsonPath('data.0.state', 'calendar_incomplete')
            ->assertJsonPath('data.0.available_days', null)
            ->assertJsonPath('data.0.booked_days', null);
        $this->actingAs($admin)->postJson($base.'/leave-balances/periods/'.
            DB::table('leave_balance_periods')->where('year', 2026)->value('id').'/adjust', [
            'revision' => 1, 'delta_days' => 1, 'reason' => 'Do not adjust without calendar',
        ])->assertUnprocessable();

        foreach (['2026-12-30' => 'workday', '2026-12-31' => 'holiday',
            '2027-01-01' => 'holiday', '2027-01-02' => 'workday',
            '2027-01-03' => 'workday'] as $day => $type) {
            DB::table('production_calendar_days')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $company,
                'work_date' => $day, 'day_type' => $type,
                'planned_minutes' => $type === 'workday' ? 480 : 0, 'revision' => 1,
            ]);
        }
        $this->actingAs($staff)->getJson($base.'/me/leave-balances?year=2026')
            ->assertJsonPath('data.0.booked_days', 1)
            ->assertJsonPath('data.0.available_days', 9);
        $this->getJson($base.'/me/leave-balances?year=2027')
            ->assertJsonPath('data.0.booked_days', 2)
            ->assertJsonPath('data.0.available_days', 8);
    }
}
