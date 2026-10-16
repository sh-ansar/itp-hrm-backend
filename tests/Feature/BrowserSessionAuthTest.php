<?php

namespace Tests\Feature;

use App\Models\User;
use App\Http\Middleware\IsolatedXsrfCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class StrictCsrfForTest extends IsolatedXsrfCookie
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

    public function test_hrm_can_issue_isolated_csrf_and_session_cookies_on_shared_hostname(): void
    {
        config()->set('hrm.xsrf_cookie_name', 'HRM-XSRF-TOKEN');
        config()->set('session.cookie', 'hrm_session');
        config()->set('session.path', '/');
        config()->set('session.domain', null);
        config()->set('session.secure', true);

        $response = $this->get('/sanctum/csrf-cookie');
        $response->assertNoContent();
        $cookies = $response->baseResponse->headers->getCookies();
        $names = array_map(static fn ($cookie): string => $cookie->getName(), $cookies);
        $this->assertContains('HRM-XSRF-TOKEN', $names);
        $this->assertContains('hrm_session', $names);
        $this->assertNotContains('XSRF-TOKEN', $names);

        foreach ($cookies as $cookie) {
            $this->assertSame('/', $cookie->getPath());
            $this->assertNull($cookie->getDomain());
            $this->assertTrue($cookie->isSecure());
            if ($cookie->getName() === 'hrm_session') {
                $this->assertTrue($cookie->isHttpOnly());
            }
            if ($cookie->getName() === 'HRM-XSRF-TOKEN') {
                $this->assertFalse($cookie->isHttpOnly());
            }
        }
    }

    public function test_stateful_api_uses_same_isolated_xsrf_cookie(): void
    {
        config()->set('hrm.xsrf_cookie_name', 'HRM-XSRF-TOKEN');
        config()->set('sanctum.stateful', ['platform.itpmining.kz']);
        $this->withHeaders([
            'Origin' => 'https://platform.itpmining.kz',
            'Referer' => 'https://platform.itpmining.kz/hrm/',
        ]);
        $response = $this->getJson('/api/v1/me');
        $response->assertUnauthorized();
        $names = array_map(
            static fn ($cookie): string => $cookie->getName(),
            $response->baseResponse->headers->getCookies(),
        );
        $this->assertContains('HRM-XSRF-TOKEN', $names);
        $this->assertNotContains('XSRF-TOKEN', $names);
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

    public function test_ip_wide_rate_limit_blocks_account_spraying(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/auth/login', [
                'email' => "synthetic{$i}@example.test",
                'password' => 'wrong',
            ])->assertUnprocessable();
        }

        $this->postJson('/auth/login', [
            'email' => 'one-more@example.test',
            'password' => 'wrong',
        ])->assertStatus(429);
    }

    public function test_stateful_api_can_read_existing_web_session(): void
    {
        $user = $this->user();
        config()->set('sanctum.stateful', ['localhost']);

        $this->withHeaders(['Referer' => 'http://localhost/', 'Origin' => 'http://localhost']);
        $this->postJson('/auth/login', $this->loginPayload())->assertOk();

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $user->id,
                'name' => 'Synthetic HR user',
            ]]);
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
        $this->app->bind(IsolatedXsrfCookie::class, StrictCsrfForTest::class);
        $this->user();

        $this->postJson('/auth/login', $this->loginPayload())->assertStatus(419);
        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_csrf_middleware_accepts_matching_token_for_login(): void
    {
        $this->app->bind(IsolatedXsrfCookie::class, StrictCsrfForTest::class);
        $this->user();

        $this->withSession(['_token' => 'synthetic-csrf-token'])
            ->withHeader('X-CSRF-TOKEN', 'synthetic-csrf-token')
            ->postJson('/auth/login', $this->loginPayload())
            ->assertOk();
    }

    public function test_csrf_middleware_rejects_logout_without_token(): void
    {
        $this->app->bind(IsolatedXsrfCookie::class, StrictCsrfForTest::class);
        $user = $this->user();

        $this->actingAs($user)->postJson('/auth/logout')->assertStatus(419);
        $this->assertTrue(Auth::guard('web')->check());
    }

    public function test_stateful_personnel_write_rejects_missing_csrf_token(): void
    {
        $this->app->bind(IsolatedXsrfCookie::class, StrictCsrfForTest::class);
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

    public function test_password_change_requires_authenticated_account(): void
    {
        $this->postJson('/auth/password', [])->assertUnauthorized();
        config()->set('hrm.browser_login_enabled', false);
        $this->actingAs($this->user())->postJson('/auth/password', [])->assertNotFound();
    }

    public function test_password_rotation_succeeds_only_with_current_secret(): void
    {
        $user = $this->user();
        $previous = $this->loginPayload()['password'];
        $next = 'New!'.Str::random(18).'9a';
        $this->actingAs($user)->postJson('/auth/password', [
            'current_password' => $previous,
            'new_password' => $next,
            'new_password_confirmation' => $next,
        ])->assertNoContent();
        $this->assertTrue(Hash::check($next, $user->fresh()->password));
        $this->assertFalse(Hash::check($previous, $user->fresh()->password));
        $this->getJson('/auth/me')->assertOk();
    }

    public function test_password_rotation_rejects_bad_old_secret_and_weak_new_secret(): void
    {
        $user = $this->user();
        $current = $this->loginPayload()['password'];
        $next = 'New!'.Str::random(18).'9a';
        $this->actingAs($user)->postJson('/auth/password', [
            'current_password' => Str::random(30),
            'new_password' => $next,
            'new_password_confirmation' => $next,
        ])->assertUnprocessable()->assertJsonValidationErrors(['current_password']);
        $this->postJson('/auth/password', [
            'current_password' => $current,
            'new_password' => 'short',
            'new_password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['new_password']);
        $this->assertTrue(Hash::check($current, $user->fresh()->password));
    }

    public function test_password_rotation_is_rate_limited_and_requires_csrf(): void
    {
        $this->app->bind(IsolatedXsrfCookie::class, StrictCsrfForTest::class);
        $user = $this->user();
        $this->actingAs($user)->postJson('/auth/password', [
            'current_password' => $this->loginPayload()['password'],
            'new_password' => 'New!'.Str::random(20).'7a',
            'new_password_confirmation' => 'mismatch',
        ])->assertStatus(419);
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
