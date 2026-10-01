<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiAuthenticationResponseTest extends TestCase
{
    public function test_protected_api_route_returns_json_unauthorized_without_accept_header(): void
    {
        $this->get('/api/v1/pos/terminal-devices')
            ->assertUnauthorized()
            ->assertHeader('content-type', 'application/json');
    }
}
