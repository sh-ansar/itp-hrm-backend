<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PersonnelDocumentRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_can_read_only_own_company_documents_without_private_paths(): void
    {
        $company = (string) Str::uuid();
        $other = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $otherEmployee = (string) Str::uuid();
        $user = User::factory()->create();

        DB::table('companies')->insert([
            ['id' => $company, 'name' => 'Visible'],
            ['id' => $other, 'name' => 'Hidden'],
        ]);
        DB::table('employees')->insert([
            ['id' => $employee, 'company_id' => $company, 'personnel_number' => 'E1', 'last_name' => 'One', 'first_name' => 'Employee'],
            ['id' => $otherEmployee, 'company_id' => $other, 'personnel_number' => 'E2', 'last_name' => 'Two', 'first_name' => 'Employee'],
        ]);
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id, 'company_id' => $company, 'role_code' => 'hr_specialist',
        ]);
        DB::table('personnel_documents')->insert([
            ['id' => (string) Str::uuid(), 'company_id' => $company, 'employee_id' => $employee,
                'document_type' => 'contract', 'title' => 'Local contract', 'status' => 'draft',
                'storage_disk' => 'private', 'storage_path' => 'classified/path.pdf', 'sha256' => str_repeat('a', 64)],
            ['id' => (string) Str::uuid(), 'company_id' => $other, 'employee_id' => $otherEmployee,
                'document_type' => 'contract', 'title' => 'Other company contract', 'status' => 'draft',
                'storage_disk' => null, 'storage_path' => null, 'sha256' => null],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/companies/{$company}/documents?document_type=contract")
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.document_writes_enabled', false)
            ->assertJsonPath('data.0.title', 'Local contract')
            ->assertJsonPath('data.0.has_file', true);
        $response->assertJsonMissing(['storage_path' => 'classified/path.pdf']);
        $this->assertStringNotContainsString('classified/path.pdf', $response->getContent());
        $this->assertStringNotContainsString(str_repeat('a', 64), $response->getContent());

        $this->actingAs($user)->getJson("/api/v1/companies/{$other}/documents")->assertForbidden();
        $this->assertDatabaseHas('hr_audit_events', ['company_id' => $company, 'action' => 'personnel.documents_listed']);
    }

    public function test_anonymous_and_manager_cannot_read_protected_documents(): void
    {
        $company = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $company, 'name' => 'Test']);
        $this->getJson("/api/v1/companies/{$company}/documents")->assertUnauthorized();

        $manager = User::factory()->create();
        DB::table('company_user_roles')->insert(['company_id' => $company, 'user_id' => $manager->id, 'role_code' => 'department_manager']);
        $this->actingAs($manager)->getJson("/api/v1/companies/{$company}/documents")->assertForbidden();
    }
    public function test_order_pending_filter_is_tenant_scoped_and_reports_full_page_count(): void
    {
        $company = (string) Str::uuid();
        $foreign = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $foreignEmployee = (string) Str::uuid();
        $hr = User::factory()->create();
        $manager = User::factory()->create();
        DB::table('companies')->insert([
            ['id' => $company, 'name' => 'Synthetic home company'],
            ['id' => $foreign, 'name' => 'Synthetic other company'],
        ]);
        DB::table('employees')->insert([
            ['id' => $employee, 'company_id' => $company, 'personnel_number' => 'PENDING-1',
                'last_name' => 'Private', 'first_name' => 'Order'],
            ['id' => $foreignEmployee, 'company_id' => $foreign, 'personnel_number' => 'PENDING-2',
                'last_name' => 'Outside', 'first_name' => 'Employee'],
        ]);
        DB::table('company_user_roles')->insert([
            ['company_id' => $company, 'user_id' => $hr->id, 'role_code' => 'hr_specialist'],
            ['company_id' => $company, 'user_id' => $manager->id, 'role_code' => 'department_manager'],
        ]);
        $records = [];
        foreach ([
            [$company, $employee, 'order', 'submitted', 'Awaiting A'],
            [$company, $employee, 'order', 'submitted', 'Awaiting B'],
            [$company, $employee, 'order', 'approved', 'Already reviewed'],
            [$company, $employee, 'contract', 'submitted', 'Different category'],
            [$foreign, $foreignEmployee, 'order', 'submitted', 'Foreign private order'],
        ] as [$tenant, $person, $kind, $state, $title]) {
            $records[] = [
                'id' => (string) Str::uuid(), 'company_id' => $tenant,
                'employee_id' => $person, 'document_type' => $kind,
                'title' => $title, 'status' => $state,
                'storage_disk' => $kind === 'order' ? 'minio' : null,
                'storage_path' => $kind === 'order' ? 'secret/never-expose.pdf' : null,
            ];
        }
        DB::table('personnel_documents')->insert($records);
        $url = "/api/v1/companies/{$company}/documents?document_type=order&status=submitted";
        $response = $this->actingAs($hr)->getJson($url)
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing(['Awaiting A', 'Awaiting B'],
            array_column($response->json('data'), 'title'));
        $this->assertStringNotContainsString('Foreign private order', $response->getContent());
        $this->assertStringNotContainsString('secret/never-expose.pdf', $response->getContent());
        $this->getJson("/api/v1/companies/{$company}/documents?status=issued")->assertUnprocessable();
        $this->actingAs($manager)->getJson($url)->assertForbidden();
        $this->actingAs($hr)->getJson("/api/v1/companies/{$foreign}/documents?status=submitted")
            ->assertForbidden();
    }


}
