<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ChangeOwnPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGINAL = 'Synthetic!OriginalPassword123';
    private const REPLACEMENT = 'Synthetic!NewLongPassword456';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('hrm.browser_login_enabled', true);
    }

    private function user(): User
    {
        return User::factory()->create([
            'email' => 'password-synthetic@example.test',
            'password' => Hash::make(self::ORIGINAL),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'current_password' => self::ORIGINAL,
            'password' => self::REPLACEMENT,
            'password_confirmation' => self::REPLACEMENT,
        ], $overrides);
    }

    public function test_guest_cannot_change_an_account_password(): void
    {
        $user = $this->user();
        $this->postJson('/auth/password', $this->payload())->assertUnauthorized();
        $this->assertTrue(Hash::check(self::ORIGINAL, $user->fresh()->password));
    }

    public function test_password_change_route_respects_production_login_switch(): void
    {
        $user = $this->user();
        config()->set('hrm.browser_login_enabled', false);
        $this->actingAs($user)->postJson('/auth/password', $this->payload())->assertNotFound();
        $this->assertTrue(Hash::check(self::ORIGINAL, $user->fresh()->password));
    }

    public function test_user_must_verify_current_password_and_confirm_new_password(): void
    {
        $user = $this->user();
        $this->actingAs($user)
            ->postJson('/auth/password', $this->payload(['current_password' => 'Invalid!Password123']))
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson('/auth/password', $this->payload(['password_confirmation' => 'Mismatch!123456']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/auth/password', $this->payload(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check(self::ORIGINAL, $user->fresh()->password));
    }

    public function test_same_password_is_rejected(): void
    {
        $user = $this->user();
        $this->actingAs($user)->postJson('/auth/password', $this->payload([
            'password' => self::ORIGINAL,
            'password_confirmation' => self::ORIGINAL,
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check(self::ORIGINAL, $user->fresh()->password));
    }

    public function test_authenticated_user_changes_only_own_password_without_leaking_secret(): void
    {
        $user = $this->user();
        $other = User::factory()->create([
            'email' => 'another-synthetic@example.test',
            'password' => Hash::make('Other!StrongPassword123'),
        ]);
        $initialToken = 'synthetic-before-password-change';
        $this->actingAs($user)->withSession(['_token' => $initialToken]);

        $response = $this->postJson('/auth/password', $this->payload())->assertNoContent();
        $response->assertDontSee(self::REPLACEMENT);
        $this->assertTrue(Hash::check(self::REPLACEMENT, $user->fresh()->password));
        $this->assertFalse(Hash::check(self::ORIGINAL, $user->fresh()->password));
        $this->assertTrue(Hash::check('Other!StrongPassword123', $other->fresh()->password));
        $this->assertNotSame($initialToken, session()->token());

        $this->getJson('/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }
}
