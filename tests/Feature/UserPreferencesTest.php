<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function payload(int $revision = 0, array $overrides = []): array
    {
        return array_replace_recursive([
            'notification_channels' => [
                'email' => true,
                'in_app' => true,
            ],
            'notification_rules' => [
                'employee_changes' => true,
                'schedule_changes' => true,
                'learning_reserve' => false,
                'document_deadlines' => true,
            ],
            'revision' => $revision,
        ], $overrides);
    }

    public function test_guest_is_unauthorized(): void
    {
        $this->getJson('/api/v1/me/preferences')->assertUnauthorized();
        $this->putJson('/api/v1/me/preferences', $this->payload())->assertUnauthorized();
    }

    public function test_default_preferences_are_returned_without_creating_a_row(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/me/preferences')
            ->assertOk()
            ->assertJsonPath('data.notification_channels.email', true)
            ->assertJsonPath('data.notification_channels.in_app', true)
            ->assertJsonPath('data.notification_rules.learning_reserve', false)
            ->assertJsonPath('data.revision', 0);

        $this->assertDatabaseCount('user_preferences', 0);
    }

    public function test_preferences_persist_and_revision_increments(): void
    {
        $user = User::factory()->create();

        $saved = $this->actingAs($user)
            ->putJson('/api/v1/me/preferences', $this->payload(0, [
                'notification_channels' => ['email' => false],
                'notification_rules' => ['learning_reserve' => true],
            ]))
            ->assertOk()
            ->assertJsonPath('data.notification_channels.email', false)
            ->assertJsonPath('data.notification_channels.in_app', true)
            ->assertJsonPath('data.notification_rules.learning_reserve', true)
            ->assertJsonPath('data.revision', 1);

        $this->actingAs($user)
            ->getJson('/api/v1/me/preferences')
            ->assertOk()
            ->assertJsonPath('data.notification_channels.email', false)
            ->assertJsonPath('data.notification_rules.learning_reserve', true)
            ->assertJsonPath('data.revision', 1);

        $this->actingAs($user)
            ->putJson('/api/v1/me/preferences', $this->payload(1, [
                'notification_channels' => ['email' => true, 'in_app' => false],
            ]))
            ->assertOk()
            ->assertJsonPath('data.notification_channels.in_app', false)
            ->assertJsonPath('data.revision', 2);

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $user->id,
            'revision' => 2,
        ]);
    }

    public function test_stale_revision_is_rejected_without_overwriting_preferences(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/v1/me/preferences', $this->payload())
            ->assertOk();

        $this->actingAs($user)
            ->putJson('/api/v1/me/preferences', $this->payload(0, [
                'notification_channels' => ['email' => false],
            ]))
            ->assertStatus(409);

        $this->actingAs($user)
            ->getJson('/api/v1/me/preferences')
            ->assertOk()
            ->assertJsonPath('data.notification_channels.email', true)
            ->assertJsonPath('data.revision', 1);
    }

    public function test_preferences_are_isolated_between_users(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first)
            ->putJson('/api/v1/me/preferences', $this->payload(0, [
                'notification_channels' => ['email' => false],
            ]))
            ->assertOk();

        $this->actingAs($second)
            ->getJson('/api/v1/me/preferences')
            ->assertOk()
            ->assertJsonPath('data.notification_channels.email', true)
            ->assertJsonPath('data.revision', 0);
    }

    public function test_unknown_preference_fields_are_rejected(): void
    {
        $user = User::factory()->create();

        $payload = $this->payload();
        $payload['notification_channels']['sms'] = true;

        $this->actingAs($user)
            ->putJson('/api/v1/me/preferences', $payload)
            ->assertUnprocessable();

        $this->assertDatabaseCount('user_preferences', 0);
    }
}
