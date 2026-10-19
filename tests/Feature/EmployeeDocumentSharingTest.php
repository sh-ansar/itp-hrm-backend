<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeeDocumentSharingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('minio');
        config()->set('hrm.document_writes_enabled', true);

        $company = (string) Str::uuid();
        $foreign = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $otherEmployee = (string) Str::uuid();
        $doc = (string) Str::uuid();
        $basis = (string) Str::uuid();
        $hr = User::factory()->create();
        $staff = User::factory()->create();
        $other = User::factory()->create();

        DB::table('companies')->insert([
            ['id' => $company, 'name' => 'Synthetic'],
            ['id' => $foreign, 'name' => 'Foreign'],
        ]);
        DB::table('employees')->insert([
            ['id' => $employee, 'company_id' => $company, 'personnel_number' => 'SELF-1',
                'first_name' => 'Own', 'last_name' => 'Employee'],
            ['id' => $otherEmployee, 'company_id' => $company, 'personnel_number' => 'OTHER-2',
                'first_name' => 'Another', 'last_name' => 'Employee'],
        ]);
        foreach ([[$hr, 'hr_specialist'], [$staff, 'employee'], [$other, 'employee']] as [$user, $role]) {
            DB::table('company_user_roles')->insert([
                'company_id' => $company, 'user_id' => $user->id, 'role_code' => $role,
            ]);
        }
        DB::table('employee_user_links')->insert([
            ['company_id' => $company, 'user_id' => $staff->id, 'employee_id' => $employee, 'revision' => 1],
            ['company_id' => $company, 'user_id' => $other->id, 'employee_id' => $otherEmployee, 'revision' => 1],
        ]);
        DB::table('personnel_documents')->insert([
            ['id' => $doc, 'company_id' => $company, 'employee_id' => $employee,
                'document_type' => 'contract', 'title' => 'Own contract', 'document_number' => 'PRIVATE-123',
                'status' => 'draft', 'revision' => 1],
            ['id' => $basis, 'company_id' => $company, 'employee_id' => $employee,
                'document_type' => 'basis', 'title' => 'Confidential HR basis', 'document_number' => null,
                'status' => 'draft', 'revision' => 1],
        ]);

        $base = "/api/v1/companies/{$company}";
        return compact('company', 'foreign', 'employee', 'otherEmployee', 'doc', 'basis',
            'hr', 'staff', 'other', 'base');
    }

    private function storePdf(string $company, string $document, string $employee): string
    {
        $file = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";
        $path = "personnel-documents/{$company}/{$document}/abcd1234567890abcdef.pdf";
        Storage::disk('minio')->put($path, $file);
        DB::table('personnel_documents')->where('company_id', $company)->where('id', $document)->update([
            'storage_disk' => 'minio', 'storage_path' => $path, 'content_type' => 'application/pdf',
            'byte_size' => strlen($file), 'sha256' => hash('sha256', $file),
        ]);
        return $path;
    }

    public function test_documents_are_private_until_explicitly_shared_and_revoke_is_immediate(): void
    {
        extract($this->fixture());
        $ownList = $base.'/me/documents';
        $ownFile = $ownList.'/'.$doc.'/file';
        $sharing = $base.'/documents/'.$doc.'/employee-access';

        $this->actingAs($staff)->getJson($ownList)->assertOk()->assertJsonCount(0, 'data');
        $this->get($ownFile)->assertNotFound();
        $this->putJson($sharing, ['revision' => 1, 'employee_visible' => true])->assertNotFound();

        $this->actingAs($hr)->putJson($sharing, ['revision' => 1, 'employee_visible' => true])->assertUnprocessable();
        $filePath = $this->storePdf($company, $doc, $employee);
        Storage::disk('minio')->assertExists($filePath);

        $this->actingAs($hr)->putJson($sharing, ['revision' => 0, 'employee_visible' => true])->assertConflict();
        $this->putJson($sharing, ['revision' => 1, 'employee_visible' => true])->assertOk()
            ->assertJsonPath('data.employee_visible', true)->assertJsonPath('data.revision', 2);
        $this->putJson($sharing, ['revision' => 1, 'employee_visible' => false])->assertConflict();

        $self = $this->actingAs($staff)->getJson($ownList)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $doc)
            ->assertJsonPath('data.0.title', 'Own contract')->assertJsonPath('meta.total', 1);
        $this->assertArrayNotHasKey('document_number', $self->json('data.0'));
        $this->assertArrayNotHasKey('storage_path', $self->json('data.0'));
        $this->assertArrayNotHasKey('sha256', $self->json('data.0'));
        $this->get($ownFile)->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($base.'/documents/'.$doc.'/file')->assertNotFound();
        $this->actingAs($other)->getJson($ownList)->assertOk()->assertJsonCount(0, 'data');
        $this->get($ownFile)->assertNotFound();
        $this->actingAs($staff)->getJson($base.'/me/documents?page=0')->assertUnprocessable();
        $this->getJson($base.'/me/documents?employee_id='.$otherEmployee)->assertUnprocessable();
        $this->getJson($base.'/documents')->assertForbidden();
        $this->get($base.'/me/documents/'.$basis.'/file')->assertNotFound();
        $this->get("/api/v1/companies/{$foreign}/me/documents/{$doc}/file")->assertNotFound();

        $this->actingAs($hr)->putJson($sharing, ['revision' => 2, 'employee_visible' => false])
            ->assertOk()->assertJsonPath('data.employee_visible', false)->assertJsonPath('data.revision', 3);
        $this->actingAs($staff)->getJson($ownList)->assertOk()->assertJsonCount(0, 'data');
        $this->get($ownFile)->assertNotFound();
        // Revocation must not delete the private HR file or break authorized HR downloads.
        Storage::disk('minio')->assertExists($filePath);
        $this->actingAs($hr)->get($base.'/documents/'.$doc.'/file')->assertOk();

        $this->assertDatabaseHas('hr_audit_events', ['company_id' => $company,
            'action' => 'personnel.document_shared_with_employee']);
        $this->assertDatabaseHas('hr_audit_events', ['company_id' => $company,
            'action' => 'personnel.document_employee_access_revoked']);
        $this->assertDatabaseHas('hr_audit_events', ['company_id' => $company,
            'action' => 'personnel.employee_document_downloaded']);
    }

    public function test_only_reviewable_private_pdf_types_can_be_shared_and_membership_revocation_blocks_reads(): void
    {
        extract($this->fixture());
        $this->storePdf($company, $basis, $employee);
        $this->actingAs($hr)->putJson($base.'/documents/'.$basis.'/employee-access',
            ['revision' => 1, 'employee_visible' => true])->assertUnprocessable();

        $this->storePdf($company, $doc, $employee);
        $link = $base.'/documents/'.$doc.'/employee-access';
        DB::table('personnel_documents')->where('id', $doc)->update(['storage_disk' => 'local']);
        $this->putJson($link, ['revision' => 1, 'employee_visible' => true])->assertUnprocessable();
        DB::table('personnel_documents')->where('id', $doc)->update(['storage_disk' => 'minio']);
        $this->putJson($link, ['revision' => 1, 'employee_visible' => true])->assertOk();
        $this->actingAs($staff)->getJson($base.'/me/documents')->assertJsonCount(1, 'data');

        DB::table('company_user_roles')->where('company_id', $company)->where('user_id', $staff->id)->delete();
        $this->getJson($base.'/me/documents')->assertForbidden();
        $this->get($base.'/me/documents/'.$doc.'/file')->assertNotFound();

        $this->actingAs($other)->get($base.'/me/documents/'.$doc.'/file')->assertNotFound();
        $this->actingAs($hr)->putJson($base.'/documents/'.$doc.'/employee-access',
            ['revision' => 2, 'employee_visible' => false])->assertOk();
        $this->assertDatabaseHas('personnel_documents', ['id' => $doc, 'employee_visible' => false]);
    }
}
