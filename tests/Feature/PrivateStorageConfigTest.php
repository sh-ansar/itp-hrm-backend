<?php

namespace Tests\Feature;

use Tests\TestCase;

final class PrivateStorageConfigTest extends TestCase
{
    public function test_employee_photo_disk_defaults_to_private_storage_or_explicit_shared_root(): void
    {
        $root = env('HRM_PRIVATE_STORAGE_ROOT') ?: storage_path('app/private');

        self::assertSame('local', config('filesystems.disks.local.driver'));
        self::assertSame($root, config('filesystems.disks.local.root'));
        self::assertArrayNotHasKey('url', config('filesystems.disks.local'));
    }

    public function test_personnel_document_minio_disk_remains_private_and_unaffected(): void
    {
        self::assertSame('s3', config('filesystems.disks.minio.driver'));
        self::assertSame('private', config('filesystems.disks.minio.visibility'));
        self::assertArrayNotHasKey('url', config('filesystems.disks.minio'));
    }
}
