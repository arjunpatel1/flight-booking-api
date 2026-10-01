<?php

namespace Tests\Unit\Printer;

use Illuminate\Routing\Route;
use Tests\TestCase;

class PrintAgentPairingSecurityTest extends TestCase
{
    public function test_pairing_routes_have_expected_authentication_boundaries_and_limits(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())->keyBy(fn (Route $route) => $route->uri());

        $create = $routes->get('api/v1/agent-pairings');
        $status = $routes->get('api/v1/agent-pairings/{pairing}/status');
        $claim = $routes->get('api/v1/print-agent-pairings/claim');

        $this->assertNotNull($create);
        $this->assertNotNull($status);
        $this->assertNotNull($claim);
        $this->assertContains('throttle:5,1', $create->gatherMiddleware());
        $this->assertContains('throttle:30,1', $status->gatherMiddleware());
        $this->assertContains('can:admin.print_agents.edit', $claim->gatherMiddleware());
        $this->assertContains('throttle:10,1', $claim->gatherMiddleware());
    }

    public function test_pairing_implementation_hashes_codes_and_uses_secure_randomness(): void
    {
        $source = file_get_contents(base_path(
            'Modules/Printer/app/Http/Controllers/Api/V1/PrintAgentPairingController.php'
        ));

        $this->assertIsString($source);
        $this->assertStringContainsString('random_int(0, 999999)', $source);
        $this->assertStringContainsString("hash_hmac('sha256', \$code", $source);
        $this->assertStringContainsString("hash('sha256', \$challengeToken)", $source);
        $this->assertStringContainsString('lockForUpdate()', $source);
        $this->assertStringContainsString("->where('device_public_id', \$data['device_public_id'])", $source);
        $this->assertStringContainsString("->update(['status' => 'revoked'])", $source);
        $this->assertStringNotContainsString("'code' => \$code", $source);
    }
}
