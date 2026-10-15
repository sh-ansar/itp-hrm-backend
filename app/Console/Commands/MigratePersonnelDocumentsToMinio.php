<?php

namespace App\Console\Commands;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Identity\AccountState;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Explicit, resumable and non-destructive legacy PDF migration.
 *
 * A migrated object is verified byte-for-byte by SHA-256 before its database
 * pointer is changed. Source files are NEVER deleted by this command.
 */
final class MigratePersonnelDocumentsToMinio extends Command
{
    protected $signature = 'hrm:documents:migrate-to-minio
        {company : Company UUID}
        {--actor= : Numeric ID of the active company administrator}
        {--execute : Explicitly enable storage and database writes}';

    protected $description = 'Preview or securely transfer legacy private personnel PDFs to MinIO';

    public function handle(HrAuditWriter $audit): int
    {
        $company = (string) $this->argument('company');
        $actorId = filter_var($this->option('actor'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $execute = (bool) $this->option('execute');

        if (! Str::isUuid($company) || $actorId === false) {
            $this->error('A valid company UUID and numeric --actor are required.');

            return self::INVALID;
        }

        $actor = User::query()->find($actorId);
        if ($actor === null || $actor->account_status !== AccountState::Active ||
            ! DB::table('company_user_roles')->where('company_id', $company)
                ->where('user_id', $actorId)->where('role_code', 'company_admin')->exists()) {
            $this->error('An active company administrator is required for this operation.');

            return self::FAILURE;
        }
        if ($execute && ! config('hrm.document_storage_migration_enabled', false)) {
            $this->error('HRM_DOCUMENT_STORAGE_MIGRATION_ENABLED must be explicitly enabled.');

            return self::FAILURE;
        }

        $query = DB::table('personnel_documents')
            ->where('company_id', $company)
            ->where('storage_disk', 'local')
            ->whereNotNull('storage_path');

        if (! $execute) {
            $this->info('Dry run: '.$query->count().' legacy PDF records eligible for review. No objects read or modified.');

            return self::SUCCESS;
        }

        $migrated = 0;
        $failed = 0;
        foreach ((clone $query)->orderBy('id')->cursor() as $record) {
            try {
                $this->migrateRecord($actor, $company, $record, $audit);
                $migrated++;
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
                $this->warn('One PDF could not be verified or migrated. It remains on its original disk.');
            }
        }

        $this->info("Result: {$migrated} migrated; {$failed} skipped. Old local files retained for independent reconciliation.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function migrateRecord(User $actor, string $company, object $row, HrAuditWriter $audit): void
    {
        $oldPath = $row->storage_path;
        if (! is_string($oldPath)
            || ! str_starts_with($oldPath, "personnel-documents/{$company}/{$row->id}/")
            || preg_match('/^[a-z0-9\/-]+\.pdf$/', $oldPath) !== 1
        ) {
            throw new RuntimeException('Non-canonical legacy object key.');
        }

        $local = Storage::disk('local');
        $private = Storage::disk('minio');
        if (! $local->exists($oldPath)) {
            throw new RuntimeException('Missing legacy PDF.');
        }

        $localStream = $local->readStream($oldPath);
        if (! is_resource($localStream)) {
            throw new RuntimeException('Unable to read legacy PDF.');
        }

        try {
            [$hash, $bytes] = $this->digest($localStream);
            if (($row->sha256 !== null && ! hash_equals($row->sha256, $hash))
                || ($row->byte_size !== null && (int) $row->byte_size !== $bytes)) {
                throw new RuntimeException('Legacy PDF checksum or size mismatch.');
            }
            if (rewind($localStream) === false) {
                throw new RuntimeException('Unable to rewind source PDF.');
            }

            $newPath = "personnel-documents/{$company}/{$row->id}/"
                .bin2hex(random_bytes(16)).'.pdf';

            try {
                $stored = $private->put($newPath, $localStream, ['visibility' => 'private']);
                if ($stored === false) {
                    throw new RuntimeException('Private object write failed.');
                }

                $privateStream = $private->readStream($newPath);
                if (! is_resource($privateStream)) {
                    throw new RuntimeException('Unable to verify uploaded PDF.');
                }
                try {
                    [$remoteHash, $remoteBytes] = $this->digest($privateStream);
                } finally {
                    fclose($privateStream);
                }
                if (! hash_equals($hash, $remoteHash) || $bytes !== $remoteBytes) {
                    throw new RuntimeException('New MinIO PDF failed integrity verification.');
                }

                DB::transaction(function () use ($actor, $company, $row, $newPath, $hash, $bytes, $audit): void {
                    $updated = DB::table('personnel_documents')
                        ->where('company_id', $company)->where('id', $row->id)
                        ->where('storage_disk', 'local')->where('storage_path', $row->storage_path)
                        ->where('revision', $row->revision)
                        ->update([
                            'storage_disk' => 'minio',
                            'storage_path' => $newPath,
                            'sha256' => $hash,
                            'byte_size' => $bytes,
                            'revision' => (int) $row->revision + 1,
                            'updated_at' => now(),
                        ]);
                    if ($updated !== 1) {
                        throw new RuntimeException('Document version changed during migration.');
                    }
                    $audit->record($actor, $company, HrAuditAction::PersonnelDocumentStorageMigrated, null, [
                        'document_id' => $row->id,
                        'revision' => (int) $row->revision + 1,
                    ]);
                }, 3);
            } catch (Throwable $exception) {
                // This object has a unique key; cleanup cannot delete a previously published document.
                try {
                    $private->delete($newPath);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
                throw $exception;
            }
        } finally {
            fclose($localStream);
        }
    }

    /** @return array{0: string, 1: int} */
    private function digest($stream): array
    {
        $context = hash_init('sha256');
        $bytes = 0;
        while (! feof($stream)) {
            $chunk = fread($stream, 1048576);
            if ($chunk === false) {
                throw new RuntimeException('Document stream failed.');
            }
            if ($chunk === '') {
                break;
            }
            $bytes += strlen($chunk);
            hash_update($context, $chunk);
        }

        return [hash_final($context), $bytes];
    }
}
