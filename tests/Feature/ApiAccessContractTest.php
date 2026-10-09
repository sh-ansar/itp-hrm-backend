<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiAccessContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_client_cannot_access_current_user(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_authenticated_client_receives_only_safe_identity_fields(): void
    {
        $user = User::factory()->create(['name' => 'Test HR User']);

        $this->actingAs($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $user->id,
                    'name' => 'Test HR User',
                ],
            ]);
    }

    public function test_anonymous_client_cannot_access_nonexistent_personnel_api(): void
    {
        $this->getJson('/api/v1/employees')->assertNotFound();
    }
}
