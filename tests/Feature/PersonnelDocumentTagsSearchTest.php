<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PersonnelDocumentTagsSearchTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $company = (string) Str::uuid();
        $other = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $foreignEmployee = (string) Str::uuid();
        $hr = User::factory()->create();
        $manager = User::factory()->create();
        DB::table('companies')->insert([
            ['id' => $company, 'name' => 'Mining demo'],
            ['id' => $other, 'name' => 'Another company'],
        ]);
        DB::table('employees')->insert([
            ['id' => $employee, 'company_id' => $company, 'personnel_number' => 'HR-100',
                'first_name' => 'Sample', 'last_name' => 'Employee'],
            ['id' => $foreignEmployee, 'company_id' => $other, 'personnel_number' => 'HR-999',
                'first_name' => 'Foreign', 'last_name' => 'Employee'],
        ]);
        DB::table('company_user_roles')->insert([
            ['company_id' => $company, 'user_id' => $hr->id, 'role_code' => 'hr_specialist'],
            ['company_id' => $company, 'user_id' => $manager->id, 'role_code' => 'department_manager'],
        ]);
        return compact('company', 'other', 'employee', 'foreignEmployee', 'hr', 'manager');
    }

    public function test_hr_can_tag_attach_section_search_and_revision_update_without_leaking_private_paths(): void
    {
        $f = $this->fixture();
        extract($f);
        config()->set('hrm.document_writes_enabled', true);
        $url = "/api/v1/companies/{$company}/documents";
        $request = [
            'employee_id' => $employee,
            'document_type' => 'other', 'title' => 'Сертификат специалиста',
            'description' => 'Грамота за внедрение цифровых решений',
            'section_key' => 'development',
            'tags' => ['Грамота', 'Развитие', ' грамота '],
        ];
        $row = $this->actingAs($hr)->postJson($url, $request)
            ->assertCreated()
            ->assertJsonPath('data.section_key', 'development')
            ->assertJsonCount(2, 'data.tags')
            ->json('data');
        $this->assertDatabaseHas('personnel_documents', [
            'id' => $row['id'], 'employee_id' => $employee, 'status' => 'draft',
            'section_key' => 'development',
        ]);
        $this->getJson($url.'?search=грамота')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.description', $request['description'])
            ->assertJsonPath('data.0.tags.0', 'Грамота')
            ->assertJsonPath('data.0.section_key', 'development');
        $this->getJson($url.'?tag=развитие')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($url.'?search=СЕРТИФИКАТ')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($url.'?section_key=personal')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($url.'?section_key=development')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($url.'?search=неизвестный')->assertOk()->assertJsonPath('meta.total', 0);

        $metadata = $url.'/'.$row['id'].'/metadata';
        $this->actingAs($manager)->putJson($metadata, [
            'revision' => 1, 'tags' => ['Unauthorized'],
        ])->assertNotFound();
        $this->actingAs($hr)->putJson($metadata, [
            'revision' => 5, 'tags' => ['Сертификат'],
        ])->assertConflict();
        $this->putJson($metadata, [
            'revision' => 1, 'description' => 'Новая грамота',
            'tags' => ['Награда', 'Премия'],
            'section_key' => 'personal',
        ])->assertOk()->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.tags.1', 'Премия');
        $this->putJson($metadata, [
            'revision' => 1, 'tags' => ['Старое значение'],
        ])->assertConflict();
        $this->getJson($url.'?search=награда')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($url.'?search=развитие')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'action' => 'personnel.document_metadata_updated',
        ]);
        $response = $this->getJson($url);
        $this->assertStringNotContainsString('storage_path', $response->getContent());
        $this->assertStringNotContainsString('sha256', $response->getContent());
    }

    public function test_company_scope_and_invalid_metadata_fail_closed(): void
    {
        $f = $this->fixture();
        extract($f);
        config()->set('hrm.document_writes_enabled', true);
        $url = "/api/v1/companies/{$company}/documents";
        $payload = [
            'employee_id' => $employee, 'document_type' => 'basis', 'title' => 'HR certificate',
            'tags' => ['safe'],
        ];
        $this->actingAs($hr)->postJson($url, [
            ...$payload, 'employee_id' => $foreignEmployee,
        ])->assertUnprocessable();
        $this->postJson($url, [...$payload, 'tags' => ['safe', '<script>']])
            ->assertUnprocessable();
        $this->postJson($url, [...$payload, 'section_key' => 'secret_system'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('personnel_documents', 0);

        $foreignId = (string) Str::uuid();
        DB::table('personnel_documents')->insert([
            'id' => $foreignId, 'company_id' => $other, 'employee_id' => $foreignEmployee,
            'document_type' => 'other', 'title' => 'Sensitive secret',
            'description' => 'Награда', 'tags' => json_encode(['Награда']), 'status' => 'draft',
        ]);
        $this->getJson($url.'?search=награда')->assertOk()->assertJsonPath('meta.total', 0);
        $this->putJson($url.'/'.$foreignId.'/metadata', [
            'revision' => 1, 'tags' => ['leak'],
        ])->assertNotFound();
        $this->actingAs($manager)->getJson($url.'?search=секрет')->assertForbidden();
        $this->getJson("/api/v1/companies/{$other}/documents?search=Награда")->assertForbidden();
        $this->assertDatabaseHas('personnel_documents', ['id' => $foreignId, 'description' => 'Награда']);
    }
}
