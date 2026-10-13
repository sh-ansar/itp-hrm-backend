<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PersonnelDocumentDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_writes_are_gated_and_company_scoped(): void
    {
        $company = (string) Str::uuid();
        $other = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $otherEmployee = (string) Str::uuid();
        $user = User::factory()->create();

        DB::table('companies')->insert([
            ['id' => $company, 'name' => 'One'],
            ['id' => $other, 'name' => 'Two'],
        ]);
        DB::table('employees')->insert([
            ['id' => $employee, 'company_id' => $company, 'personnel_number' => 'ONE-1', 'last_name' => 'Test', 'first_name' => 'One'],
            ['id' => $otherEmployee, 'company_id' => $other, 'personnel_number' => 'TWO-1', 'last_name' => 'Test', 'first_name' => 'Two'],
        ]);
        DB::table('company_user_roles')->insert([
            'company_id' => $company, 'user_id' => $user->id, 'role_code' => 'hr_specialist',
        ]);
        $endpoint = "/api/v1/companies/{$company}/documents";
        $payload = [
            'employee_id' => $employee,
            'document_type' => 'contract',
            'title' => 'Employment contract draft',
            'document_number' => 'DRAFT-001',
            'issued_on' => '2026-10-13',
        ];

        config()->set('hrm.document_writes_enabled', false);
        $this->actingAs($user)->postJson($endpoint, $payload)->assertNotFound();
        $this->assertDatabaseCount('personnel_documents', 0);

        config()->set('hrm.document_writes_enabled', true);
        $this->actingAs($user)->postJson($endpoint, [...$payload, 'employee_id' => $otherEmployee])
            ->assertUnprocessable();
        $this->actingAs($user)->postJson($endpoint, [...$payload, 'storage_path' => 'public/leak'])
            ->assertUnprocessable();

        $this->actingAs($user)->postJson($endpoint, $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.revision', 1);
        $this->assertDatabaseCount('personnel_documents', 1);
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company,
            'action' => 'personnel.document_draft_created',
        ]);

        $manager = User::factory()->create();
        DB::table('company_user_roles')->insert([
            'company_id' => $company, 'user_id' => $manager->id, 'role_code' => 'department_manager',
        ]);
        $this->actingAs($manager)->postJson($endpoint, $payload)->assertNotFound();
        $this->assertDatabaseCount('personnel_documents', 1);
    }
}
