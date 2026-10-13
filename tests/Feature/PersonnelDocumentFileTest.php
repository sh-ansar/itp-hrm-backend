<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PersonnelDocumentFileTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_upload_and_download_enforce_role_company_and_revision(): void
    {
        Storage::fake('local');
        $company=(string) Str::uuid();
        $other=(string) Str::uuid();
        $employee=(string) Str::uuid();
        $document=(string) Str::uuid();
        $hr=User::factory()->create();
        $manager=User::factory()->create();

        DB::table('companies')->insert([
            ['id'=>$company,'name'=>'Visible'],
            ['id'=>$other,'name'=>'Other'],
        ]);
        DB::table('employees')->insert([
            'id'=>$employee,'company_id'=>$company,'personnel_number'=>'E-100',
            'last_name'=>'Example','first_name'=>'Employee',
        ]);
        DB::table('company_user_roles')->insert([
            ['user_id'=>$hr->id,'company_id'=>$company,'role_code'=>'hr_specialist'],
            ['user_id'=>$manager->id,'company_id'=>$company,'role_code'=>'department_manager'],
        ]);
        DB::table('personnel_documents')->insert([
            'id'=>$document,'company_id'=>$company,'employee_id'=>$employee,
            'document_type'=>'contract','title'=>'Demo contract','status'=>'draft','revision'=>1,
        ]);

        $url="/api/v1/companies/{$company}/documents/{$document}/file";
        $pdf="%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";
        config()->set('hrm.document_writes_enabled', false);
        $this->actingAs($hr)->post($url,[
            'file'=>UploadedFile::fake()->createWithContent('sample.pdf',$pdf),'revision'=>1,
        ])->assertNotFound();
        config()->set('hrm.document_writes_enabled', true);

        $this->actingAs($manager)->post($url,[
            'file'=>UploadedFile::fake()->createWithContent('sample.pdf',$pdf),'revision'=>1,
        ])->assertNotFound();
        $this->actingAs($hr)->post($url,[
            'file'=>UploadedFile::fake()->createWithContent('sample.pdf',$pdf),'revision'=>2,
        ])->assertStatus(409);
        $this->actingAs($hr)->post($url,[
            'file'=>UploadedFile::fake()->createWithContent('sample.pdf',$pdf),'revision'=>1,
        ])->assertOk()->assertJsonPath('data.revision',2);

        $row=DB::table('personnel_documents')->where('id',$document)->first();
        $this->assertSame('local',$row->storage_disk);
        Storage::disk('local')->assertExists($row->storage_path);
        $this->assertStringStartsWith('personnel-documents/',$row->storage_path);

        $this->actingAs($manager)->get($url)->assertNotFound();
        $this->actingAs($hr)->get("/api/v1/companies/{$other}/documents/{$document}/file")->assertNotFound();
        $this->actingAs($hr)->get($url)->assertOk()->assertHeader('content-type','application/pdf');
        $this->actingAs($hr)->post($url,[
            'file'=>UploadedFile::fake()->createWithContent('sample.pdf',$pdf),'revision'=>2,
        ])->assertStatus(409);
        $this->assertDatabaseHas('hr_audit_events',['company_id'=>$company,'action'=>'personnel.document_file_attached']);
        $this->assertDatabaseHas('hr_audit_events',['company_id'=>$company,'action'=>'personnel.document_file_downloaded']);
    }
}
