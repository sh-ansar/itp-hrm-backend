<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorktimePeriodReviewTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $admin = User::factory()->create();
        $hr = User::factory()->create();
        $staff = User::factory()->create();
        $company = (string) Str::uuid();
        $employee = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $company, 'name' => 'Period QA Company']);
        foreach ([$admin->id => 'company_admin', $hr->id => 'hr_specialist', $staff->id => 'employee'] as $id => $role) {
            DB::table('company_user_roles')->insert([
                'company_id' => $company, 'user_id' => $id, 'role_code' => $role,
            ]);
        }
        DB::table('employees')->insert([
            'id' => $employee, 'company_id' => $company, 'personnel_number' => 'PERIOD-QA',
            'last_name' => 'Test', 'first_name' => 'Employee', 'revision' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('production_calendar_days')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $company, 'work_date' => '2026-10-15',
            'day_type' => 'workday', 'planned_minutes' => 480,
            'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $path = "/api/v1/companies/{$company}/worktime";
        return [$admin, $hr, $staff, $company, $employee, $path];
    }

    private function entry(): array
    {
        return ['month' => '2026-10', 'days' => [[
            'date' => '2026-10-15', 'worked_minutes' => 480,
            'absence_minutes' => 0, 'overtime_minutes' => 0, 'revision' => null,
        ]]];
    }

    public function test_period_review_is_fail_closed_by_default(): void
    {
        [$admin, , , , , $url] = $this->fixture();
        config()->set('hrm.worktime_writes_enabled', true);
        $this->actingAs($admin)->getJson($url.'/periods?month=2026-10')->assertNotFound();
        $this->actingAs($admin)->postJson($url.'/periods/transition', [
            'month' => '2026-10', 'revision' => 0, 'action' => 'submit',
        ])->assertNotFound();
    }

    public function test_four_eyes_submission_approval_lock_and_audited_reopen(): void
    {
        config()->set('hrm.worktime_writes_enabled', true);
        config()->set('hrm.worktime_period_approvals_enabled', true);
        [$admin, $hr, $staff, $company, $employee, $url] = $this->fixture();
        $path = $url.'/timesheet/'.$employee;
        $this->actingAs($hr)->putJson($path, $this->entry())->assertOk();
        $this->assertContains($this->actingAs($staff)->getJson($url.'/periods?month=2026-10')->getStatusCode(), [403, 404]);
        $this->actingAs($hr)->getJson($url.'/periods?month=2026-10')
            ->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.revision', 0);

        $submit = ['month' => '2026-10', 'revision' => 0, 'action' => 'submit'];
        $this->actingAs($hr)->postJson($url.'/periods/transition', $submit)
            ->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.revision', 1);
        $this->actingAs($hr)->getJson($path.'?month=2026-10')
            ->assertOk()->assertJsonPath('meta.can_edit', false);
        $this->actingAs($hr)->putJson($path, [
            'month' => '2026-10', 'days' => [[...$this->entry()['days'][0], 'revision' => 1]],
        ])->assertStatus(409);
        $this->actingAs($hr)->postJson($url.'/periods/transition', [
            'month' => '2026-10', 'revision' => 1, 'action' => 'approve',
        ])->assertForbidden();
        $this->actingAs($admin)->postJson($url.'/periods/transition', [
            'month' => '2026-10', 'revision' => 1, 'action' => 'approve',
        ])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.revision', 2);

        $this->actingAs($admin)->postJson($url.'/periods/transition', [
            'month' => '2026-10', 'revision' => 1, 'action' => 'reopen', 'reason' => 'Verified correction',
        ])->assertStatus(409);
        $this->actingAs($hr)->postJson($url.'/periods/transition', [
            'month' => '2026-10', 'revision' => 2, 'action' => 'reopen', 'reason' => 'Verified correction',
        ])->assertForbidden();
        $this->actingAs($admin)->postJson($url.'/periods/transition', [
            'month' => '2026-10', 'revision' => 2, 'action' => 'reopen', 'reason' => 'Verified correction',
        ])->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.revision', 3);
        $this->actingAs($hr)->putJson($path, [
            'month' => '2026-10', 'days' => [[...$this->entry()['days'][0], 'revision' => 1, 'worked_minutes' => 420]],
        ])->assertOk()->assertJsonPath('data.0.revision', 2);

        $this->assertSame(1, DB::table('hr_audit_events')->where('company_id', $company)
            ->where('action', 'worktime.period_submitted')->count());
        $this->assertSame(1, DB::table('hr_audit_events')->where('company_id', $company)
            ->where('action', 'worktime.period_approved')->count());
        $this->assertSame(1, DB::table('hr_audit_events')->where('company_id', $company)
            ->where('action', 'worktime.period_reopened')->count());
    }

    public function test_submitting_without_any_entries_is_rejected(): void
    {
        config()->set('hrm.worktime_writes_enabled', true);
        config()->set('hrm.worktime_period_approvals_enabled', true);
        [, $hr, , $company, , $url] = $this->fixture();
        $this->actingAs($hr)->postJson($url.'/periods/transition', [
            'month' => '2026-10', 'revision' => 0, 'action' => 'submit',
        ])->assertStatus(409);
        $this->assertDatabaseMissing('worktime_periods', ['company_id' => $company]);
    }
}
