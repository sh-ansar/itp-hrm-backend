<?php

namespace Tests\Feature;

use App\Domain\Identity\AccountLifecycle;
use App\Domain\Identity\AccountState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountRevocationRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function syntheticUser(AccountState $status = AccountState::Active): User
    {
        return User::factory()->create([
            'name' => 'Synthetic regression user',
            'email' => 'revocation@example.test',
            'password' => Hash::make('Synthetic!VeryLongPassword123'),
            'account_status' => $status,
        ]);
    }

    public function test_active_identity_remains_authorized_for_minimal_api(): void
    {
        $this->actingAs($this->syntheticUser())
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'Synthetic regression user');
    }

    public function test_all_nonactive_identities_are_denied_api_requests(): void
    {
        foreach ([AccountState::Invited, AccountState::Suspended, AccountState::Disabled] as $status) {
            $user = $this->syntheticUser($status);
            $this->actingAs($user)->getJson('/api/v1/me')->assertUnauthorized();
            $user->forceDelete();
        }
    }

    public function test_existing_browser_session_loses_access_after_disable(): void
    {
        config()->set('hrm.browser_login_enabled', true);
        $user = $this->syntheticUser();

        $this->postJson('/auth/login', [
            'email' => 'revocation@example.test',
            'password' => 'Synthetic!VeryLongPassword123',
        ])->assertOk();

        $this->getJson('/auth/me')->assertOk();

        app(AccountLifecycle::class)->changeState($user, AccountState::Disabled);

        $this->getJson('/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_suspension_does_not_modify_credential_or_company_membership(): void
    {
        $user = $this->syntheticUser();
        $passwordHash = $user->password;

        app(AccountLifecycle::class)->changeState($user, AccountState::Suspended);

        $this->assertSame($passwordHash, $user->fresh()->password);
        $this->assertSame(AccountState::Suspended, $user->fresh()->account_status);
        $this->assertFalse(Auth::guard('web')->check());
    }
}
