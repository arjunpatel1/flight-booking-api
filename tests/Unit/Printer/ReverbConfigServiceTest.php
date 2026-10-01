<?php

namespace Tests\Unit\Printer;

use Illuminate\Http\Request;
use Modules\Printer\Services\Reverb\ReverbConfigService;
use Tests\TestCase;

class ReverbConfigServiceTest extends TestCase
{
    public function test_reverb_config_service_returns_app_key_and_socket_url(): void
    {
        config()->set('broadcasting.connections.reverb.key', 'nexdine-local-key');
        config()->set('broadcasting.connections.reverb.options', [
            'host' => '127.0.0.1',
            'port' => 8080,
            'scheme' => 'http',
            'path' => '',
        ]);

        $service = new ReverbConfigService();
        $request = Request::create('https://example.test/api/v1/agents/AGENT-ONE/setup', 'GET');

        $this->assertSame('nexdine-local-key', $service->getAppKey());
        $this->assertSame(
            'ws://127.0.0.1:8080/app/nexdine-local-key?protocol=7&client=nexdine-print-agent&version=1.0.0&flash=false',
            $service->getSocketUrl($request)
        );
        $this->assertSame(
            [
                'app_key' => 'nexdine-local-key',
                'socket_url' => 'ws://127.0.0.1:8080/app/nexdine-local-key?protocol=7&client=nexdine-print-agent&version=1.0.0&flash=false',
            ],
            $service->toArray($request)
        );
    }

    public function test_reverb_config_service_uses_app_url_before_request_host_when_host_is_not_configured(): void
    {
        config()->set('app.url', 'https://pos.example.test');
        config()->set('broadcasting.connections.reverb.key', 'nexdine-local-key');
        config()->set('broadcasting.connections.reverb.options', [
            'host' => '',
            'port' => 443,
            'scheme' => 'https',
            'path' => '',
        ]);

        $service = new ReverbConfigService();
        $request = Request::create('https://attacker.example/api/v1/agents/AGENT-ONE/setup', 'GET');

        $this->assertSame(
            'wss://pos.example.test/app/nexdine-local-key?protocol=7&client=nexdine-print-agent&version=1.0.0&flash=false',
            $service->getSocketUrl($request)
        );
    }

    public function test_reverb_config_service_normalizes_configured_host_with_scheme(): void
    {
        config()->set('broadcasting.connections.reverb.key', 'nexdine-local-key');
        config()->set('broadcasting.connections.reverb.options', [
            'host' => 'https://socket.example.test',
            'port' => 6001,
            'scheme' => 'https',
            'path' => '',
        ]);

        $service = new ReverbConfigService();
        $request = Request::create('https://example.test/api/v1/agents/AGENT-ONE/setup', 'GET');

        $this->assertSame(
            'wss://socket.example.test:6001/app/nexdine-local-key?protocol=7&client=nexdine-print-agent&version=1.0.0&flash=false',
            $service->getSocketUrl($request)
        );
    }
}
