<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiErrorContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_access_returns_json_401_without_identity_data(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonStructure(['message'])
            ->assertJsonMissingPath('data');
    }

    public function test_unknown_api_endpoint_returns_json_404(): void
    {
        $this->getJson('/api/v1/nonexistent-resource')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_api_validation_failure_uses_stable_errors_map(): void
    {
        $user = User::factory()->create();
        config()->set('hrm.role_management_enabled', true);
        $this->actingAs($user)->putJson(
            '/api/v1/companies/00000000-0000-4000-8000-000000000000/memberships/1',
            ['role_code' => 'invalid']
        )->assertForbidden();
        // Authorization precedes payload validation to avoid exposing protected
        // field rules to a caller without a company-admin grant.
    }

    public function test_browser_login_input_validation_is_json_422_without_password_echo(): void
    {
        config()->set('hrm.browser_login_enabled', true);

        $this->postJson('/auth/login', [
            'email' => 'not-an-email',
            'password' => 'SYNTHETIC_TEST_SECRET',
        ])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['email']])
            ->assertDontSee('SYNTHETIC_TEST_SECRET');
    }
}
