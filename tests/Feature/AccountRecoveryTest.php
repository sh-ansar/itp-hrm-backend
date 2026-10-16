<?php

namespace Tests\Feature;

use App\Domain\Identity\AccountState;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

final class AccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PASSWORD = 'Synthetic!RecoveryOld123';
    private const NEW_PASSWORD = 'Synthetic!RecoveryNew456';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('hrm.password_reset_enabled', true);
    }

    private function user(string $email = 'recovery@example.test', AccountState $state = AccountState::Active): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => Hash::make(self::OLD_PASSWORD),
            'account_status' => $state,
        ]);
    }

    public function test_recovery_routes_are_fail_closed_when_feature_is_disabled(): void
    {
        config()->set('hrm.password_reset_enabled', false);
        $this->postJson('/auth/password/request', ['email' => 'nobody@example.test'])->assertNotFound();
        $this->postJson('/auth/password/reset', [])->assertNotFound();
    }

    public function test_request_does_not_enumerate_unknown_or_inactive_accounts(): void
    {
        Notification::fake();
        $active = $this->user('active@example.test');
        $this->user('disabled@example.test', AccountState::Disabled);

        $this->postJson('/auth/password/request', ['email' => 'unknown@example.test'])->assertNoContent();
        $this->postJson('/auth/password/request', ['email' => 'disabled@example.test'])->assertNoContent();
        $this->postJson('/auth/password/request', ['email' => strtoupper($active->email)])->assertNoContent();

        Notification::assertSentToTimes($active, ResetPassword::class, 1);
        Notification::assertCount(1);
    }

    public function test_valid_reset_changes_only_active_account_password_and_invalidates_token(): void
    {
        $user = $this->user();
        $token = Password::broker('users')->createToken($user);

        $payload = [
            'email' => strtoupper($user->email),
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];

        $this->postJson('/auth/password/reset', $payload)->assertNoContent();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));

        $this->postJson('/auth/password/reset', $payload)->assertUnprocessable();
    }

    public function test_reset_rejects_inactive_account_even_with_a_real_token(): void
    {
        $user = $this->user('suspended@example.test', AccountState::Suspended);
        $token = Password::broker('users')->createToken($user);

        $this->postJson('/auth/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertUnprocessable();

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $user->fresh()->password));
    }
}
