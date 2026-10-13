<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeeDevelopmentRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name): string
    {
        $id = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $id, 'name' => $name]);
        return $id;
    }

    private function employee(string $company, string $number, string $lastName): string
    {
        $id = (string) Str::uuid();
        DB::table('employees')->insert([
            'id' => $id, 'company_id' => $company,
            'personnel_number' => $number, 'first_name' => 'Synthetic',
            'last_name' => $lastName,
        ]);
        return $id;
    }

    private function grant(User $user, string $company, string $role): void
    {
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id, 'company_id' => $company, 'role_code' => $role,
        ]);
    }

    private function training(string $employee, string $topic, string $from, ?string $to): void
    {
        DB::table('employee_training')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $employee,
            'training_type' => 'seminar', 'training_mode' => 'remote',
            'topic' => $topic, 'started_on' => $from, 'ended_on' => $to,
        ]);
    }

    private function reserve(string $employee, string $title, ?int $leftYear, string $privateNotes): void
    {
        DB::table('employee_reserve')->insert([
            'id' => (string) Str::uuid(), 'employee_id' => $employee,
            'target_position' => $title, 'reserve_level' => 'senior',
            'reserve_type' => 'internal', 'joined_year' => 2024,
            'left_year' => $leftYear, 'notes' => $privateNotes,
        ]);
    }

    public function test_training_company_filters_pagination_and_audit_do_not_leak_sensitive_data(): void
    {
        $company = $this->company('Allowed');
        $other = $this->company('Denied');
        $a = $this->employee($company, 'A-001', 'Visible');
        $b = $this->employee($company, 'A-002', 'Another');
        $hidden = $this->employee($other, 'B-001', 'Hidden');
        $today = now();
        $this->training($a, 'Safety', $today->copy()->subDays(30)->toDateString(), $today->copy()->subDays(20)->toDateString());
        $this->training($b, 'Leadership', $today->copy()->subDay()->toDateString(), $today->copy()->addDay()->toDateString());
        $this->training($a, 'Planning', $today->copy()->addDays(10)->toDateString(), null);
        $this->training($hidden, 'Secret course', $today->copy()->subDay()->toDateString(), null);
        $hr = User::factory()->create();
        $this->grant($hr, $company, 'hr_specialist');
        $url = "/api/v1/companies/{$company}/development/training";

        $response = $this->actingAs($hr)->getJson($url.'?per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.summary.total', 3)
            ->assertJsonPath('meta.summary.completed', 1)
            ->assertJsonPath('meta.summary.ongoing', 1)
            ->assertJsonPath('meta.summary.planned', 1)
            ->assertJsonCount(1, 'data');
        $response->assertJsonMissing(['topic' => 'Secret course']);

        $this->actingAs($hr)->getJson($url.'?status=completed')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.development_status', 'completed')
            ->assertJsonPath('data.0.topic', 'Safety');

        $this->actingAs($hr)->getJson($url.'?status=ongoing&search=Leadership')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.personnel_number', 'A-002');

        $this->actingAs($hr)->getJson($url.'?status=planned&search=A-001')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.topic', 'Planning');

        $this->actingAs($hr)->getJson($url.'?search=Secret')
            ->assertOk()->assertJsonPath('meta.total', 0);

        $this->actingAs($hr)->getJson($url.'?status=unknown')->assertUnprocessable();
        $this->actingAs($hr)->getJson($url.'?per_page=101')->assertUnprocessable();
        $this->actingAs($hr)->getJson("/api/v1/companies/{$other}/development/training")->assertForbidden();

        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'action' => 'personnel.development_records_listed',
        ]);
        $audit = DB::table('hr_audit_events')->where('action', 'personnel.development_records_listed')->pluck('context')->implode(' ');
        $this->assertStringNotContainsString('Leadership', $audit);
        $this->assertStringNotContainsString('A-001', $audit);
        $this->assertStringNotContainsString($a, $audit);
    }

    public function test_reserve_summary_protected_notes_and_access_roles(): void
    {
        $company = $this->company('Allowed');
        $other = $this->company('Other');
        $employee = $this->employee($company, 'A-101', 'Visible');
        $hidden = $this->employee($other, 'B-101', 'Hidden');
        $this->reserve($employee, 'Senior manager', null, 'Private assessment: do not expose');
        $this->reserve($employee, 'Team lead', 2025, 'Private note 2');
        $this->reserve($hidden, 'Secret position', null, 'Restricted');
        $admin = User::factory()->create();
        $manager = User::factory()->create();
        $this->grant($admin, $company, 'company_admin');
        $this->grant($manager, $company, 'department_manager');
        $url = "/api/v1/companies/{$company}/development/reserve";

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($manager)->getJson($url)->assertForbidden();
        $this->actingAs($admin)->getJson($url.'?status=active')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.summary.total', 2)
            ->assertJsonPath('meta.summary.active', 1)
            ->assertJsonPath('meta.summary.alumni', 1)
            ->assertJsonPath('data.0.development_status', 'active')
            ->assertJsonMissingPath('data.0.notes')
            ->assertJsonMissing(['target_position' => 'Secret position']);
        $this->actingAs($admin)->getJson($url.'?status=alumni&search=Team')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.target_position', 'Team lead');
        $response = $this->actingAs($admin)->getJson($url);
        $this->assertStringNotContainsString('Private assessment', $response->getContent());
        $this->actingAs($admin)->getJson($url.'?status=completed')->assertUnprocessable();
        $this->actingAs($admin)->getJson("/api/v1/companies/{$other}/development/reserve")->assertForbidden();
        $this->actingAs($admin)->getJson("/api/v1/companies/{$company}/development/unexpected")->assertNotFound();
    }
}
