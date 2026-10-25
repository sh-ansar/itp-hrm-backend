<?php

namespace Tests\Feature;

use Database\Seeders\ItpSolutionsQaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ItpSolutionsQaSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('HRM_QA_SEED_TARGET'); putenv('HRM_QA_ACCOUNT_PASSWORD');
        parent::tearDown();
    }

    public function test_catalogue_is_repeatable_and_contains_no_real_identifiers(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The exact named persistent PostgreSQL QA target is separately guarded and verified.');
        }
        putenv('HRM_QA_SEED_TARGET=sqlite-unit-tests');
        putenv('HRM_QA_ACCOUNT_PASSWORD=Synthetic-Unit-Only!2026');
        $this->seed(ItpSolutionsQaSeeder::class);
        $this->seed(ItpSolutionsQaSeeder::class);
        $this->assertDatabaseCount('employees', 32);
        $this->assertDatabaseCount('departments', 15);
        $this->assertDatabaseCount('positions', 30);
        $this->assertDatabaseCount('work_schedules', 3);
        $this->assertDatabaseCount('employee_assignments', 34);
        $this->assertDatabaseCount('personnel_actions', 37);
        $this->assertDatabaseCount('employee_leaves', 3);
        $this->assertDatabaseCount('employee_bonuses', 2);
        $this->assertDatabaseCount('employee_training', 4);
        $this->assertDatabaseCount('employee_user_links', 4);
        $this->assertSame(0, DB::table('employees')->whereNotNull('phone')->count());
        $this->assertSame(32, DB::table('employees')->where('email', 'like', '%@hrm.example.test')->count());
        $this->assertSame(30, DB::table('employee_assignments')->whereNull('valid_to')->count());
        $this->assertDatabaseHas('personnel_actions', ['action_type' => 'transfer']);
    }

    public function test_production_environment_is_rejected_before_any_records_are_created(): void
    {
        $this->app['env'] = 'production';
        try { app(ItpSolutionsQaSeeder::class)->run(); $this->fail('Production must be refused'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('local/testing', $error->getMessage()); }
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_missing_opt_in_is_rejected_before_any_records_are_created(): void
    {
        putenv('HRM_QA_SEED_TARGET');
        try { $this->seed(ItpSolutionsQaSeeder::class); $this->fail('Opt-in is required'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('isolated HRM QA', $error->getMessage()); }
        $this->assertDatabaseCount('employees', 0);
    }
}
