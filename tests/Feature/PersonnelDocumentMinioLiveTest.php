<?php

namespace Tests\Feature;

use App\Domain\Identity\AccountState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Runs ONLY against an ephemeral MinIO process with synthetic documents.
 * Never connect this test to an actual company bucket or production server.
 */
final class PersonnelDocumentMinioLiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_minio_requires_authentication_and_laravel_roles(): void
    {
        if (getenv('HRM_RUN_MINIO_INTEGRATION') !== '1') {
            $this->markTestSkipped('Real MinIO is exercised only in the isolated storage CI job.');
        }

        $endpoint = (string) getenv('MINIO_ENDPOINT');
        $bucket = (string) getenv('MINIO_BUCKET');
        self::assertSame('http://127.0.0.1:9000', $endpoint);
        self::assertSame('hrm-ci-private', $bucket);
        config()->set('hrm.document_writes_enabled', true);
        config()->set('filesystems.disks.minio', [
            'driver' => 's3',
            'key' => getenv('MINIO_ACCESS_KEY'),
            'secret' => getenv('MINIO_SECRET_KEY'),
            'region' => 'us-east-1',
            'bucket' => $bucket,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
            'throw' => true,
        ]);
        Storage::forgetDisk('minio');

        $company = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $document = (string) Str::uuid();
        $otherCompany = (string) Str::uuid();
        $admin = User::factory()->create();
        $manager = User::factory()->create();

        DB::table('companies')->insert([
            ['id' => $company, 'name' => 'Synthetic company'],
            ['id' => $otherCompany, 'name' => 'Synthetic second company'],
        ]);
        DB::table('employees')->insert([
            'id' => $employee, 'company_id' => $company,
            'personnel_number' => 'CI-1', 'first_name' => 'Synthetic', 'last_name' => 'Employee',
        ]);
        DB::table('company_user_roles')->insert([
            ['company_id' => $company, 'user_id' => $admin->id, 'role_code' => 'hr_specialist'],
            ['company_id' => $company, 'user_id' => $manager->id, 'role_code' => 'department_manager'],
        ]);
        DB::table('personnel_documents')->insert([
            'id' => $document, 'company_id' => $company, 'employee_id' => $employee,
            'document_type' => 'contract', 'title' => 'Synthetic private PDF',
            'status' => 'draft', 'revision' => 1,
        ]);

        $url = "/api/v1/companies/{$company}/documents/{$document}/file";
        $pdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";
        $this->actingAs($admin)->post($url, [
            'file' => UploadedFile::fake()->createWithContent('synthetic.pdf', $pdf),
            'revision' => 1,
        ])->assertOk()->assertJsonPath('data.revision', 2);

        $row = DB::table('personnel_documents')->where('id', $document)->first();
        self::assertSame('minio', $row->storage_disk);
        self::assertStringStartsWith("personnel-documents/{$company}/{$document}/", $row->storage_path);
        self::assertTrue(Storage::disk('minio')->exists($row->storage_path));
        $migratedKey = null;

        try {
            // MinIO must not expose the object directly by its known complete path.
            $direct = Http::timeout(3)->get("{$endpoint}/{$bucket}/{$row->storage_path}");
            self::assertContains($direct->status(), [401, 403, 404]);

            $this->actingAs($manager)->get($url)->assertNotFound();
            $this->actingAs($admin)
                ->get("/api/v1/companies/{$otherCompany}/documents/{$document}/file")
                ->assertNotFound();

            $response = $this->actingAs($admin)->get($url)->assertOk()
                ->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            self::assertSame($pdf, $response->streamedContent());

            // Now migrate a separate legacy PDF through the same real MinIO.
            $legacyId = (string) Str::uuid();
            $legacyPath = "personnel-documents/{$company}/{$legacyId}/"
                .str_repeat('b', 32).'.pdf';
            Storage::fake('local');
            Storage::disk('local')->put($legacyPath, $pdf);
            DB::table('personnel_documents')->insert([
                'id' => $legacyId, 'company_id' => $company, 'employee_id' => $employee,
                'document_type' => 'basis', 'title' => 'Synthetic historical PDF',
                'status' => 'draft', 'storage_disk' => 'local', 'storage_path' => $legacyPath,
                'sha256' => hash('sha256', $pdf), 'byte_size' => strlen($pdf),
                'revision' => 1,
            ]);
            $admin->forceFill(['account_status' => AccountState::Active])->save();
            DB::table('company_user_roles')->where('company_id', $company)
                ->where('user_id', $admin->id)->update(['role_code' => 'company_admin']);
            config()->set('hrm.document_storage_migration_enabled', true);

            self::assertSame(0, Artisan::call('hrm:documents:migrate-to-minio', [
                'company' => $company, '--actor' => $admin->id, '--execute' => true,
            ]));
            $migrated = DB::table('personnel_documents')->where('id', $legacyId)->first();
            self::assertSame('minio', $migrated->storage_disk);
            self::assertSame(2, (int) $migrated->revision);
            self::assertTrue(Storage::disk('local')->exists($legacyPath));
            $migratedKey = $migrated->storage_path;
            $legacyResponse = $this->actingAs($admin)
                ->get("/api/v1/companies/{$company}/documents/{$legacyId}/file")
                ->assertOk();
            self::assertSame($pdf, $legacyResponse->streamedContent());
            $directMigrated = Http::timeout(3)->get("{$endpoint}/{$bucket}/{$migratedKey}");
            self::assertContains($directMigrated->status(), [401, 403, 404]);

            // Revocation takes effect immediately, including for existing sessions.
            DB::table('company_user_roles')->where('company_id', $company)
                ->where('user_id', $admin->id)->delete();
            $this->actingAs($admin)->get($url)->assertNotFound();
            $this->actingAs($admin)->get("/api/v1/companies/{$company}/documents/{$legacyId}/file")
                ->assertNotFound();
            $this->assertDatabaseHas('hr_audit_events', [
                'company_id' => $company,
                'action' => 'personnel.document_file_downloaded',
            ]);
        } finally {
            Storage::disk('minio')->delete($row->storage_path);
            if ($migratedKey !== null) {
                Storage::disk('minio')->delete($migratedKey);
            }
        }
    }
}
