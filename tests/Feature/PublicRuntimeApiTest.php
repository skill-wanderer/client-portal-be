<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicRuntimeApiTest extends TestCase
{
    public function test_health_endpoint_returns_public_json_contract(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'service' => 'client-portal-be',
            ]);
    }

    public function test_database_test_endpoint_returns_public_json_contract(): void
    {
        $response = $this->getJson('/api/v1/test-db');

        $response
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'database' => 'connected',
            ]);
    }
}
