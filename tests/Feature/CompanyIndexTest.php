<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CompanyIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_cannot_read_company_index(): void
    {
        $this->getJson('/api/v1/companies')->assertUnauthorized();
    }

    public function test_authenticated_user_without_membership_sees_no_companies(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->getJson('/api/v1/companies')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_page_size_is_bounded(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->getJson('/api/v1/companies?per_page=101')
            ->assertUnprocessable();
    }
}
