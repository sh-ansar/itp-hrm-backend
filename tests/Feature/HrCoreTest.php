<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class HrCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_has_versioned_contract(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'service' => 'itp-hrm-api']);
    }

    public function test_hr_core_schema_and_employee_number_uniqueness(): void
    {
        foreach (['companies','departments','positions','employees','employee_assignments'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $company = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $company, 'name' => 'Demo Organization']);
        $employee = [
            'id' => (string) Str::uuid(),
            'company_id' => $company,
            'personnel_number' => 'EMP-001',
            'first_name' => 'Demo',
            'last_name' => 'Employee',
        ];
        DB::table('employees')->insert($employee);
        $this->assertSame(1, DB::table('employees')->where('company_id', $company)->count());
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $employee['id'] = (string) Str::uuid();
        DB::table('employees')->insert($employee);
    }
}
