<?php

namespace Tests\Unit\Printer;

use Illuminate\Http\Request;
use Modules\Printer\Transformers\Api\V1\PrintAgentResource;
use Tests\TestCase;

class PrintAgentResourceReverbSetupTest extends TestCase
{
    public function test_print_agent_setup_includes_reverb_socket_url_and_broadcast_auth_url(): void
    {
        config()->set('broadcasting.connections.reverb.key', 'nexdine-local-key');
        config()->set('broadcasting.connections.reverb.secret', 'nexdine-local-secret');
        config()->set('broadcasting.connections.reverb.app_id', 'nexdine-local-app');
        config()->set('broadcasting.connections.reverb.options', [
            'host' => '127.0.0.1',
            'port' => 8080,
            'scheme' => 'http',
            'useTLS' => false,
            'path' => '',
        ]);

        $agent = new class {
            public $id = 1;
            public $name = 'Test Agent';
            public $agent_id = 'AGENT-ONE';
            public $branch_id = 7;
            public $secret = 'secret-value';
            public $is_active = true;
            public $last_seen_at = null;
            public $created_at = null;
            public $updated_at = null;

            public function relationLoaded(string $relation): bool
            {
                return false;
            }
        };

        $resource = new PrintAgentResource($agent);
        $request = Request::create('https://example.test/api/v1/agents/AGENT-ONE/setup', 'GET');

        $payload = $resource->toArray($request);

        $this->assertSame('https://example.test/api/v1', $payload['setup']['server_url']);
        $this->assertSame('nexdine-local-key', $payload['setup']['reverb_app_key']);
        $this->assertSame('private-agent.AGENT-ONE', $payload['setup']['websocket_channel']);
        $this->assertSame('https://example.test/api/v1/agents/AGENT-ONE/broadcasting/auth', $payload['setup']['urls']['broadcast_auth']);
        $this->assertSame('ws://127.0.0.1:8080/app/nexdine-local-key?protocol=7&client=nexdine-print-agent&version=1.0.0&flash=false', $payload['setup']['reverb_socket_url']);
    }
}
