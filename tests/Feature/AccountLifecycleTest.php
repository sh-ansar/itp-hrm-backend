<?php

namespace Tests\Feature;

use App\Domain\Identity\AccountLifecycle;
use App\Domain\Identity\AccountState;
use App\Domain\Identity\CompanyRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Synthetic account',
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('Synthetic!VeryLongPassword123'),
        ], $overrides));
    }

    private function company(): string
    {
        $id = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $id, 'name' => 'Synthetic company']);

        return $id;
    }

    private function admin(User $user, string $company): void
    {
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id,
            'company_id' => $company,
            'role_code' => CompanyRole::CompanyAdmin->value,
        ]);
    }

    public function test_existing_users_remain_active_by_default(): void
    {
        $user = $this->user();

        $this->assertSame(AccountState::Active, $user->fresh()->account_status);
    }

    public function test_inactive_account_login_uses_same_public_error_as_wrong_password(): void
    {
        config()->set('hrm.browser_login_enabled', true);
        $user = $this->user(['email' => 'inactive@example.test']);

        app(AccountLifecycle::class)->changeState($user, AccountState::Suspended);

        $inactive = $this->postJson('/auth/login', [
            'email' => 'inactive@example.test',
            'password' => 'Synthetic!VeryLongPassword123',
        ])->assertUnprocessable()->json('errors.email.0');

        $wrong = $this->postJson('/auth/login', [
            'email' => 'inactive@example.test',
            'password' => 'wrong',
        ])->assertUnprocessable()->json('errors.email.0');

        $this->assertSame($inactive, $wrong);
    }

    public function test_suspended_user_is_denied_on_existing_authenticated_request(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $this->getJson('/api/v1/me')->assertOk();

        app(AccountLifecycle::class)->changeState($user, AccountState::Suspended);

        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_deactivation_removes_database_sessions_for_user(): void
    {
        $user = $this->user();
        DB::table('sessions')->insert([
            'id' => 'synthetic-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'synthetic',
            'payload' => 'synthetic',
            'last_activity' => time(),
        ]);

        app(AccountLifecycle::class)->changeState($user, AccountState::Disabled);

        $this->assertDatabaseMissing('sessions', ['id' => 'synthetic-session']);
    }

    public function test_repeated_state_change_is_idempotent(): void
    {
        $user = $this->user();
        $service = app(AccountLifecycle::class);

        $this->assertTrue($service->changeState($user, AccountState::Suspended));
        $this->assertFalse($service->changeState($user->fresh(), AccountState::Suspended));
    }

    public function test_last_active_company_admin_cannot_be_deactivated(): void
    {
        $company = $this->company();
        $admin = $this->user();
        $this->admin($admin, $company);

        $this->expectException(ValidationException::class);
        app(AccountLifecycle::class)->changeState($admin, AccountState::Disabled);
    }

    public function test_company_admin_can_be_deactivated_when_another_active_admin_exists(): void
    {
        $company = $this->company();
        $first = $this->user();
        $second = $this->user();
        $this->admin($first, $company);
        $this->admin($second, $company);

        $this->assertTrue(app(AccountLifecycle::class)->changeState($first, AccountState::Suspended));
        $this->assertSame(AccountState::Suspended, $first->fresh()->account_status);
    }

    public function test_console_path_changes_state_without_public_http_endpoint(): void
    {
        $user = $this->user();

        $this->artisan('hrm:account-status', [
            'user' => $user->id,
            'status' => 'suspended',
        ])->assertExitCode(0);

        $this->assertSame(AccountState::Suspended, $user->fresh()->account_status);
    }
}
