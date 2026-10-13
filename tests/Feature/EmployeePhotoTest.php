<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmployeePhotoTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('local');
        config()->set('hrm.personnel_writes_enabled', true);
        $company=(string) Str::uuid(); $other=(string) Str::uuid(); $employee=(string) Str::uuid();
        $hr=User::factory()->create(); $manager=User::factory()->create();
        DB::table('companies')->insert([['id'=>$company,'name'=>'Synthetic'],['id'=>$other,'name'=>'Other']]);
        DB::table('employees')->insert(['id'=>$employee,'company_id'=>$company,'personnel_number'=>'P-001','last_name'=>'Synthetic','first_name'=>'Person','revision'=>1]);
        DB::table('company_user_roles')->insert([
            ['company_id'=>$company,'user_id'=>$hr->id,'role_code'=>'hr_specialist'],
            ['company_id'=>$company,'user_id'=>$manager->id,'role_code'=>'department_manager'],
            ['company_id'=>$other,'user_id'=>$hr->id,'role_code'=>'hr_specialist'],
        ]);
        $url="/api/v1/companies/{$company}/employees/{$employee}/photo";
        return compact('company','other','employee','hr','manager','url');
    }

    public function test_private_photo_upload_replacement_and_removal_preserve_history_and_revision(): void
    {
        extract($this->fixture());
        $this->actingAs($hr)->post($url,['file'=>UploadedFile::fake()->image('personal-name.png',80,120),'revision'=>1])
            ->assertOk()->assertJsonPath('data.revision',2);
        $old=DB::table('employees')->where('id',$employee)->value('photo_path');
        Storage::disk('local')->assertExists($old);
        $this->assertStringNotContainsString('personal-name',$old);
        $this->assertSame('image/jpeg',getimagesizefromstring(Storage::disk('local')->get($old))['mime']);
        $this->actingAs($hr)->get($url)->assertOk()->assertHeader('Content-Type','image/jpeg')
            ->assertHeader('X-Content-Type-Options','nosniff');
        $detail=$this->actingAs($hr)->getJson("/api/v1/companies/{$company}/employees/{$employee}")
            ->assertOk()->assertJsonPath('data.has_photo',true)->assertJsonMissingPath('data.photo_path');
        $this->assertStringNotContainsString('employee-photos/',$detail->getContent());
        $this->actingAs($hr)->post($url,['file'=>UploadedFile::fake()->image('replacement.jpg',120,80),'revision'=>2])
            ->assertOk()->assertJsonPath('data.revision',3);
        Storage::disk('local')->assertMissing($old);
        $path=DB::table('employees')->where('id',$employee)->value('photo_path');
        $this->actingAs($hr)->deleteJson($url,['revision'=>2])->assertConflict();
        Storage::disk('local')->assertExists($path);
        $this->actingAs($hr)->deleteJson($url,['revision'=>3])->assertOk()->assertJsonPath('data.has_photo',false);
        Storage::disk('local')->assertMissing($path);
        $this->actingAs($hr)->get($url)->assertNotFound();
        $this->actingAs($hr)->getJson("/api/v1/companies/{$company}/employees/{$employee}")
            ->assertOk()->assertJsonPath('data.has_photo',false)->assertJsonCount(3,'history')
            ->assertJsonPath('history.0.action','personnel.employee_photo_removed');
    }

    public function test_photo_is_hidden_from_managers_other_companies_and_disabled_writes(): void
    {
        extract($this->fixture());
        $this->get($url)->assertUnauthorized();
        $this->actingAs($manager)->get($url)->assertNotFound();
        $this->actingAs($manager)->post($url,['file'=>UploadedFile::fake()->image('photo.png'),'revision'=>1])->assertNotFound();
        $this->actingAs($hr)->post("/api/v1/companies/{$other}/employees/{$employee}/photo",[
            'file'=>UploadedFile::fake()->image('photo.png'),'revision'=>1,
        ])->assertNotFound();
        config()->set('hrm.personnel_writes_enabled',false);
        $this->actingAs($hr)->post($url,['file'=>UploadedFile::fake()->image('photo.png'),'revision'=>1])->assertNotFound();
        $this->actingAs($hr)->deleteJson($url,['revision'=>1])->assertNotFound();
        $this->assertCount(0,Storage::disk('local')->allFiles());
    }

    public function test_invalid_images_stale_revision_and_unknown_fields_leave_no_files(): void
    {
        extract($this->fixture());
        $this->actingAs($hr)->post($url,['file'=>UploadedFile::fake()->image('photo.png'),'revision'=>2])->assertConflict();
        $this->actingAs($hr)->postJson($url,['file'=>'not-a-file','revision'=>1])->assertUnprocessable();
        $this->actingAs($hr)->post($url,['file'=>UploadedFile::fake()->createWithContent('photo.svg','<svg></svg>'),'revision'=>1],['Accept'=>'application/json'])->assertUnprocessable();
        $this->actingAs($hr)->post($url,['file'=>UploadedFile::fake()->image('oversized.png',4097,1),'revision'=>1],['Accept'=>'application/json'])->assertUnprocessable();
        $this->actingAs($hr)->post($url,['file'=>UploadedFile::fake()->image('photo.png'),'revision'=>1,'company_id'=>$other],['Accept'=>'application/json'])->assertUnprocessable();
        $this->assertCount(0,Storage::disk('local')->allFiles());
        $this->assertDatabaseHas('employees',['id'=>$employee,'revision'=>1,'photo_path'=>null]);
    }
    public function test_audit_failure_rolls_back_replacement_and_removes_new_file(): void
    {
        extract($this->fixture());
        $this->actingAs($hr)->post($url,['file'=>UploadedFile::fake()->image('first.png'),'revision'=>1])->assertOk();
        $old=DB::table('employees')->where('id',$employee)->value('photo_path');
        DB::statement('CREATE UNIQUE INDEX photo_audit_failure ON hr_audit_events(company_id, action)');
        $this->actingAs($hr)->post($url,['file'=>UploadedFile::fake()->image('second.png'),'revision'=>2],['Accept'=>'application/json'])->assertStatus(500);
        $this->assertDatabaseHas('employees',['id'=>$employee,'revision'=>2,'photo_path'=>$old]);
        $this->assertSame([$old],Storage::disk('local')->allFiles());
    }

    public function test_photo_dimensions_are_normalized_and_original_bytes_not_retained(): void
    {
        extract($this->fixture());
        $this->actingAs($hr)->post($url,['file'=>UploadedFile::fake()->image('wide.png',2048,100),'revision'=>1])->assertOk();
        $path=DB::table('employees')->where('id',$employee)->value('photo_path');
        $size=getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame(1024,$size[0]);
        $this->assertSame(50,$size[1]);
        $this->assertCount(1,Storage::disk('local')->allFiles());
    }

}
