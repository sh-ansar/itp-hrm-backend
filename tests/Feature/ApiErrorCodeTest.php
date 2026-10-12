<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiErrorCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_response_has_stable_machine_code(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED')
            ->assertHeader('X-Error-Code', 'UNAUTHENTICATED')
            ->assertJsonStructure(['message']);
    }

    public function test_forbidden_company_operation_retains_message(): void
    {
        config()->set('hrm.role_management_enabled', true);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/v1/companies/00000000-0000-4000-8000-000000000000/memberships/1', [
                'role_code' => 'employee',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_success_payload_is_unchanged(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'service' => 'itp-hrm-api'])
            ->assertHeaderMissing('X-Error-Code');
    }
}
