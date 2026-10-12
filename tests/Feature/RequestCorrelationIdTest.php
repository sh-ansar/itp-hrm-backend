<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestCorrelationIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_response_has_server_generated_request_id(): void
    {
        $response = $this->getJson('/api/v1/health');
        $response->assertOk();

        $id = $response->headers->get('X-Request-ID');
        $this->assertNotNull($id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $id);
    }

    public function test_caller_supplied_correlation_id_is_not_trusted(): void
    {
        $response = $this->withHeader('X-Request-ID', 'user-controlled-id')->getJson('/api/v1/health');
        $response->assertOk();
        $this->assertNotSame('user-controlled-id', $response->headers->get('X-Request-ID'));
    }

    public function test_each_request_receives_distinct_id(): void
    {
        $first = $this->getJson('/api/v1/health')->headers->get('X-Request-ID');
        $second = $this->getJson('/api/v1/health')->headers->get('X-Request-ID');
        $this->assertNotSame($first, $second);
    }

    public function test_unauthenticated_api_response_preserves_id(): void
    {
        $response = $this->getJson('/api/v1/me');
        $response->assertUnauthorized();
        $this->assertNotNull($response->headers->get('X-Request-ID'));
    }
}
