<?php

namespace Tests\Unit\Printer;

use Illuminate\Routing\Route;
use Modules\Printer\Http\Middleware\ValidateAgentSignature;
use Tests\TestCase;

class AgentSetupRouteSecurityTest extends TestCase
{
    public function test_agent_setup_requires_signature_and_rate_limiting(): void
    {
        /** @var Route|null $route */
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn (Route $route): bool => $route->uri() === 'api/v1/agents/{agent_id}/setup');

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        $this->assertContains(ValidateAgentSignature::class, $middleware);
        $this->assertContains('throttle:10,1', $middleware);
    }
}
