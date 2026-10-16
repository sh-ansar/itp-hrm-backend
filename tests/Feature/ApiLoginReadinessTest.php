<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ApiLoginReadinessTest extends TestCase
{
    public function test_health_check_reports_login_disabled_without_disclosing_accounts(): void
    {
        config()->set('hrm.browser_login_enabled', false);

        $response = $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'service' => 'itp-hrm-api',
                'capabilities' => [
                    'browser_login' => false,
                    'password_reset' => false,
                ],
            ]);
        $response->assertDontSee('email')
            ->assertDontSee('credential')
            ->assertDontSee('token');

        // The login endpoint remains closed; observing health does not enable it.
        $this->postJson('/auth/login', [])->assertNotFound();
    }

    public function test_health_check_reflects_explicit_auth_capability_activation(): void
    {
        config()->set('hrm.browser_login_enabled', true);
        config()->set('hrm.password_reset_enabled', true);

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('capabilities.browser_login', true)
            ->assertJsonPath('capabilities.password_reset', true);
    }
}
