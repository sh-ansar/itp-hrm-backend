<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class StrictCsrfForTest extends ValidateCsrfToken
{
    // Laravel disables CSRF verification during ordinary PHPUnit runs.
    // Override only in explicit CSRF integration tests.
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

class BrowserSessionAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('hrm.browser_login_enabled', true);
    }

    private function user(): User
    {
        return User::factory()->create([
            'name' => 'Synthetic HR user',
            'email' => 'synthetic@example.test',
            'password' => Hash::make('Synthetic!VeryLongPassword123'),
        ]);
    }

    private function loginPayload(?string $email = null, ?string $password = null): array
    {
        return [
            'email' => $email ?? 'synthetic@example.test',
            'password' => $password ?? 'Synthetic!VeryLongPassword123',
        ];
    }

    public function test_browser_login_endpoints_are_off_by_default(): void
    {
        config()->set('hrm.browser_login_enabled', false);
        $user = $this->user();

        $this->postJson('/auth/login', $this->loginPayload())->assertNotFound();
        $this->actingAs($user)->getJson('/auth/me')->assertNotFound();
        $this->postJson('/auth/logout')->assertNotFound();
    }

    public function test_anonymous_current_user_and_logout_are_rejected(): void
    {
        $this->getJson('/auth/me')->assertUnauthorized();
        $this->postJson('/auth/logout')->assertUnauthorized();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_login_uses_session_and_returns_only_minimal_identity(): void
    {
        $user = $this->user();
        $initialToken = 'initial-test-csrf-token';
        $this->withSession(['_token' => $initialToken]);

        $this->postJson('/auth/login', $this->loginPayload())
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $user->id,
                'name' => 'Synthetic HR user',
            ]])
            ->assertDontSee('synthetic@example.test')
            ->assertDontSee('Synthetic!VeryLongPassword123');

        $this->assertTrue(Auth::guard('web')->check());
        $this->assertNotSame($initialToken, session()->token());
        $this->getJson('/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonMissingPath('data.email');
    }

    public function test_unknown_account_and_wrong_password_have_identical_message(): void
    {
        $this->user();

        $known = $this->postJson('/auth/login', $this->loginPayload(password: 'invalid'))
            ->assertUnprocessable()
            ->json('errors.email.0');

        $unknown = $this->postJson('/auth/login', $this->loginPayload(email: 'unknown@example.test', password: 'invalid'))
            ->assertUnprocessable()
            ->json('errors.email.0');

        $this->assertSame($known, $unknown);
        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_five_failed_attempts_limit_login_by_account_and_ip(): void
    {
        $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/auth/login', $this->loginPayload(password: 'wrong'))
                ->assertUnprocessable();
        }

        $this->postJson('/auth/login', $this->loginPayload())
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_successful_login_resets_principal_attempt_counter(): void
    {
        $this->user();

        $this->postJson('/auth/login', $this->loginPayload(password: 'wrong'))->assertUnprocessable();
        $this->postJson('/auth/login', $this->loginPayload())->assertOk();

        $this->assertTrue(Auth::guard('web')->check());
        $this->assertSame(0, RateLimiter::attempts(
            'hrm:login:principal:'.hash('sha256', 'synthetic@example.test|127.0.0.1'),
        ));
    }

    public function test_logout_invalidates_authenticated_session(): void
    {
        $this->user();
        $this->postJson('/auth/login', $this->loginPayload())->assertOk();
        $this->postJson('/auth/logout')->assertNoContent();
        $this->assertFalse(Auth::guard('web')->check());
        $this->getJson('/auth/me')->assertUnauthorized();
    }

    public function test_login_rejects_invalid_input_without_authentication(): void
    {
        $this->user();

        $this->postJson('/auth/login', [
            'email' => 'invalid email',
            'password' => Str::random(1025),
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);

        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_cookie_settings_that_disable_http_only_are_rejected(): void
    {
        config()->set('session.http_only', false);
        $this->postJson('/auth/login', $this->loginPayload())->assertStatus(503);
    }

    public function test_unsafe_same_site_settings_are_rejected(): void
    {
        config()->set('session.same_site', 'none');
        $this->postJson('/auth/login', $this->loginPayload())->assertStatus(503);
    }

    public function test_csrf_middleware_rejects_missing_token_for_login(): void
    {
        $this->app->bind(ValidateCsrfToken::class, StrictCsrfForTest::class);
        $this->user();

        $this->postJson('/auth/login', $this->loginPayload())->assertStatus(419);
        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_csrf_middleware_accepts_matching_token_for_login(): void
    {
        $this->app->bind(ValidateCsrfToken::class, StrictCsrfForTest::class);
        $this->user();

        $this->withSession(['_token' => 'synthetic-csrf-token'])
            ->withHeader('X-CSRF-TOKEN', 'synthetic-csrf-token')
            ->postJson('/auth/login', $this->loginPayload())
            ->assertOk();
    }

    public function test_csrf_middleware_rejects_logout_without_token(): void
    {
        $this->app->bind(ValidateCsrfToken::class, StrictCsrfForTest::class);
        $user = $this->user();

        $this->actingAs($user)->postJson('/auth/logout')->assertStatus(419);
        $this->assertTrue(Auth::guard('web')->check());
    }

    public function test_stateful_personnel_write_rejects_missing_csrf_token(): void
    {
        $this->app->bind(ValidateCsrfToken::class, StrictCsrfForTest::class);
        config()->set('sanctum.stateful', ['localhost']);
        $user = $this->user();
        $company = (string) Str::uuid();

        $this->actingAs($user)
            ->withHeaders(['Referer' => 'http://localhost/', 'Origin' => 'http://localhost'])
            ->putJson("/api/v1/companies/{$company}/memberships/{$user->id}", [
                'role_code' => 'employee',
            ])
            ->assertStatus(419);
    }

    public function test_preflight_cors_does_not_trust_arbitrary_origins(): void
    {
        $this->withHeaders([
            'Origin' => 'https://untrusted.example.test',
            'Access-Control-Request-Method' => 'POST',
        ])->optionsJson('/auth/login')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
