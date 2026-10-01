<?php

namespace Tests\Unit\Printer;

use Illuminate\Http\Request;
use Modules\Printer\Http\Controllers\Api\V1\AgentPollController;
use Modules\Printer\Services\AgentPoll\AgentPollServiceInterface;
use Tests\TestCase;

class AgentPollControllerBroadcastAuthTest extends TestCase
{
    public function test_broadcasting_auth_returns_reverb_signature_for_agent_channel(): void
    {
        $service = $this->createMock(AgentPollServiceInterface::class);
        $controller = new AgentPollController($service);

        config()->set('broadcasting.connections.reverb.key', 'REVERB-KEY');
        config()->set('broadcasting.connections.reverb.secret', 'REVERB-SECRET');

        $socketId = '123.456';
        $channelName = 'private-agent.AGENT-ONE';

        $request = Request::create(
            '/api/v1/agents/AGENT-ONE/broadcasting/auth',
            'POST',
            [
                'socket_id' => $socketId,
                'channel_name' => $channelName,
            ]
        );
        $request->agent = (object) ['agent_id' => 'AGENT-ONE'];

        $response = $controller->broadcastingAuth($request);

        $this->assertSame(200, $response->status());
        $this->assertSame([
            'auth' => 'REVERB-KEY:' . hash_hmac('sha256', "{$socketId}:{$channelName}", 'REVERB-SECRET'),
        ], $response->getData(true));
    }
}
