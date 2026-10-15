<?php

namespace Tests\Feature;

use App\Domain\Identity\AccountState;
use App\Domain\Identity\CompanyRole;
use App\Models\User;
use Database\Seeders\ItpCompaniesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

final class ItpCompaniesBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_bootstrap_is_disabled_by_default(): void
    {
        config()->set('hrm.company_bootstrap_enabled', false);
        $this->expectException(RuntimeException::class);
        (new ItpCompaniesSeeder())->run();
    }

    public function test_portal_references_seed_three_companies_only_once(): void
    {
        config()->set('hrm.company_bootstrap_enabled', true);
        $seeder = new ItpCompaniesSeeder();
        $seeder->run();
        $ids = DB::table('companies')->orderBy('name')->pluck('id')->all();
        $seeder->run();
        $this->assertSame($ids, DB::table('companies')->orderBy('name')->pluck('id')->all());
        $this->assertDatabaseCount('companies', 3);
        $this->assertDatabaseCount('company_external_references', 3);
        $this->assertDatabaseHas('companies', ['name' => 'ITP Mining']);
        $this->assertDatabaseHas('companies', ['name' => 'ITP Georgia']);
        $this->assertDatabaseHas('companies', ['name' => 'ITP Solutions']);
        $this->assertDatabaseHas('company_external_references', [
            'source_system' => 'itp_platform', 'source_id' => '3',
            'source_name' => 'ITP Solution',
        ]);
    }

    public function test_mapping_conflict_aborts_without_partial_creation(): void
    {
        config()->set('hrm.company_bootstrap_enabled', true);
        $id = 'fdb3428b-9a32-49b1-9e28-f6f58c1e197b';
        DB::table('companies')->insert(['id' => $id, 'name' => 'Wrong External Company']);
        DB::table('company_external_references')->insert([
            'company_id' => $id,
            'source_system' => 'itp_platform',
            'source_id' => '2',
            'source_name' => 'ITP Georgia',
        ]);
        try {
            (new ItpCompaniesSeeder())->run();
            $this->fail('Seeding must refuse inconsistent external mapping');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Referenced company', $exception->getMessage());
        }
        $this->assertDatabaseCount('companies', 1);
    }

    public function test_administrator_requires_explicit_flag_and_creates_no_test_accounts(): void
    {
        config()->set('hrm.company_bootstrap_enabled', true);
        config()->set('hrm.admin_bootstrap_enabled', false);
        (new ItpCompaniesSeeder())->run();

        $tmp = tempnam(sys_get_temp_dir(), 'hrm-admin-synthetic-');
        file_put_contents($tmp, 'SyntheticStrong123!');
        try {
            try {
                $this->artisan('hrm:bootstrap-itp-admin', [
                    'email' => 'synthetic.admin@example.test',
                    '--password-file' => $tmp,
                ])->assertSuccessful();
                $this->fail('Disabled admin bootstrap must reject');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('disabled', $exception->getMessage());
            }
            $this->assertDatabaseCount('users', 0);

            config()->set('hrm.admin_bootstrap_enabled', true);
            $this->artisan('hrm:bootstrap-itp-admin', [
                'email' => 'synthetic.admin@example.test',
                '--password-file' => $tmp,
            ])->assertSuccessful();
            $user = User::query()->where('email', 'synthetic.admin@example.test')->firstOrFail();
            $this->assertTrue(Hash::check('SyntheticStrong123!', $user->password));
            $this->assertSame(AccountState::Active, $user->account_status);
            $this->assertSame(3, DB::table('company_user_roles')
                ->where('user_id', $user->id)
                ->where('role_code', CompanyRole::CompanyAdmin->value)->count());
            try {
                $this->artisan('hrm:bootstrap-itp-admin', [
                    'email' => 'synthetic.admin@example.test',
                    '--password-file' => $tmp,
                ])->assertSuccessful();
                $this->fail('Second admin bootstrap must never reset an account');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('already exists', $exception->getMessage());
            }
            $this->assertDatabaseCount('users', 1);
        } finally {
            @unlink($tmp);
        }
    }
}
