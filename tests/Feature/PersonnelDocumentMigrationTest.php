<?php

namespace Tests\Feature;

use App\Domain\Identity\AccountState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PersonnelDocumentMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(?string $storedHash = null): array
    {
        Storage::fake('local');
        Storage::fake('minio');
        config()->set('hrm.document_storage_migration_enabled', false);

        $company = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $document = (string) Str::uuid();
        $actor = User::factory()->create(['account_status' => AccountState::Active->value]);
        $pdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";
        $path = "personnel-documents/{$company}/{$document}/"
            .str_repeat('a', 32).'.pdf';

        DB::table('companies')->insert(['id' => $company, 'name' => 'Synthetic']);
        DB::table('company_user_roles')->insert([
            'company_id' => $company, 'user_id' => $actor->id, 'role_code' => 'company_admin',
        ]);
        DB::table('employees')->insert([
            'id' => $employee, 'company_id' => $company,
            'personnel_number' => 'CI-LEGACY', 'first_name' => 'Synthetic', 'last_name' => 'Employee',
        ]);
        DB::table('personnel_documents')->insert([
            'id' => $document, 'company_id' => $company, 'employee_id' => $employee,
            'document_type' => 'basis', 'title' => 'Existing PDF',
            'storage_disk' => 'local', 'storage_path' => $path,
            'sha256' => $storedHash ?? hash('sha256', $pdf),
            'byte_size' => strlen($pdf),
            'status' => 'draft', 'revision' => 1,
        ]);
        Storage::disk('local')->put($path, $pdf);

        return compact('company', 'document', 'actor', 'path', 'pdf');
    }

    public function test_dry_run_cannot_change_files_and_execution_is_opt_in(): void
    {
        extract($this->fixture());
        $options = ['company' => $company, '--actor' => $actor->id];

        $this->artisan('hrm:documents:migrate-to-minio', $options)->assertExitCode(0);
        $this->assertDatabaseHas('personnel_documents', [
            'id' => $document, 'storage_disk' => 'local', 'revision' => 1,
        ]);
        $this->artisan('hrm:documents:migrate-to-minio', $options + [
            '--execute' => true,
        ])->assertExitCode(1);
        $this->assertDatabaseHas('personnel_documents', [
            'id' => $document, 'storage_disk' => 'local', 'revision' => 1,
        ]);
    }

    public function test_verified_transfer_updates_only_pointer_and_keeps_original(): void
    {
        extract($this->fixture());
        config()->set('hrm.document_storage_migration_enabled', true);
        $options = ['company' => $company, '--actor' => $actor->id, '--execute' => true];

        $this->artisan('hrm:documents:migrate-to-minio', $options)->assertExitCode(0);
        $row = DB::table('personnel_documents')->where('id', $document)->first();
        $this->assertSame('minio', $row->storage_disk);
        $this->assertSame(2, (int) $row->revision);
        $this->assertSame(hash('sha256', $pdf), $row->sha256);
        $this->assertSame($pdf, Storage::disk('minio')->get($row->storage_path));
        $this->assertTrue(Storage::disk('local')->exists($path));

        $this->artisan('hrm:documents:migrate-to-minio', $options)->assertExitCode(0);
        $this->assertDatabaseCount('hr_audit_events', 1);
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'action' => 'personnel.document_storage_migrated',
        ]);
    }

    public function test_failed_checksum_preserves_original_pointer_and_never_publishes_blob(): void
    {
        extract($this->fixture(str_repeat('0', 64)));
        config()->set('hrm.document_storage_migration_enabled', true);
        $this->artisan('hrm:documents:migrate-to-minio', [
            'company' => $company, '--actor' => $actor->id, '--execute' => true,
        ])->assertExitCode(1);
        $this->assertDatabaseHas('personnel_documents', [
            'id' => $document, 'storage_disk' => 'local', 'storage_path' => $path,
            'revision' => 1,
        ]);
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertSame([], Storage::disk('minio')->allFiles());
        $this->assertDatabaseCount('hr_audit_events', 0);
    }

    public function test_unauthorized_or_suspended_actor_cannot_migrate(): void
    {
        extract($this->fixture());
        config()->set('hrm.document_storage_migration_enabled', true);
        DB::table('company_user_roles')->where('company_id', $company)
            ->where('user_id', $actor->id)->delete();

        $options = ['company' => $company, '--actor' => $actor->id, '--execute' => true];
        $this->artisan('hrm:documents:migrate-to-minio', $options)->assertExitCode(1);

        DB::table('company_user_roles')->insert([
            'company_id' => $company, 'user_id' => $actor->id, 'role_code' => 'company_admin',
        ]);
        $actor->forceFill(['account_status' => AccountState::Disabled])->save();
        $this->artisan('hrm:documents:migrate-to-minio', $options)->assertExitCode(1);
        $this->assertDatabaseHas('personnel_documents', [
            'id' => $document, 'storage_disk' => 'local', 'revision' => 1,
        ]);
    }
}
