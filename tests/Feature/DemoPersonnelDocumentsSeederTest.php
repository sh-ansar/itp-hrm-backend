<?php

namespace Tests\Feature;

use Database\Seeders\DemoPersonnelDocumentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class DemoPersonnelDocumentsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_document_seeder_refuses_the_test_database(): void
    {
        $this->assertNotSame('itp_hrm_demo', DB::connection()->getDatabaseName());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('local itp_hrm_demo');

        (new DemoPersonnelDocumentsSeeder())->run();
    }
}
