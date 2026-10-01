<?php

namespace Tests\Unit\Printer;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Modules\Printer\Http\Controllers\Api\V1\AgentPollController;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Models\Printer;
use Modules\Printer\Services\AgentPoll\AgentPollServiceInterface;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class AgentPollControllerSetupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('print_agents');
        Schema::create('print_agents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('agent_id')->unique();
            $table->string('secret');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('printers');
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('name');
            $table->string('connection_type');
            $table->string('provider_type')->nullable();
            $table->json('options')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function test_setup_returns_reverb_socket_url_and_broadcast_auth_url(): void
    {
        config()->set('broadcasting.connections.reverb.key', 'REVERB-KEY');
        config()->set('broadcasting.connections.reverb.secret', 'REVERB-SECRET');
        config()->set('broadcasting.connections.reverb.options', [
            'host' => '127.0.0.1',
            'port' => 8080,
            'scheme' => 'http',
            'path' => '',
            'useTLS' => false,
        ]);

        $agent = new PrintAgent([
            'branch_id' => 7,
            'agent_id' => 'AGENT-ONE',
            'secret' => 'secret-value',
            'name' => 'Test Agent',
            'is_active' => true,
        ]);
        $agent->saveQuietly();

        $service = $this->createMock(AgentPollServiceInterface::class);
        $controller = new AgentPollController($service);

        $request = Request::create('https://example.test/api/v1/agents/AGENT-ONE/setup', 'GET');

        $response = $controller->setup($request, 'AGENT-ONE');

        $this->assertSame(200, $response->status());
        $this->assertSame([
            'server_url' => 'https://example.test/api/v1',
            'agent_id' => 'AGENT-ONE',
            'branch_id' => '7',
            'mode' => 'reverb',
            'reverb_app_key' => 'REVERB-KEY',
            'reverb_socket_url' => 'ws://127.0.0.1:8080/app/REVERB-KEY?protocol=7&client=nexdine-print-agent&version=1.0.0&flash=false',
            'websocket_channel' => 'private-agent.AGENT-ONE',
            'websocket_event' => 'print.job.created',
            'assigned_printers' => [],
            'urls' => [
                'poll' => 'https://example.test/api/v1/agents/AGENT-ONE/poll',
                'fetch_job' => 'https://example.test/api/v1/agents/AGENT-ONE/jobs/{job_id}',
                'report' => 'https://example.test/api/v1/agents/AGENT-ONE/report',
                'heartbeat' => 'https://example.test/api/v1/agents/AGENT-ONE/heartbeat',
                'broadcast_auth' => 'https://example.test/api/v1/agents/AGENT-ONE/broadcasting/auth',
            ],
        ], $response->getData(true));
    }

    public function test_setup_includes_assigned_printers_for_agent(): void
    {
        config()->set('broadcasting.connections.reverb.key', 'REVERB-KEY');
        config()->set('broadcasting.connections.reverb.secret', 'REVERB-SECRET');
        config()->set('broadcasting.connections.reverb.options', [
            'host' => '127.0.0.1',
            'port' => 8080,
            'scheme' => 'http',
            'path' => '',
            'useTLS' => false,
        ]);

        $agent = new PrintAgent([
            'branch_id' => 7,
            'agent_id' => 'AGENT-ONE',
            'secret' => 'secret-value',
            'name' => 'Test Agent',
            'is_active' => true,
        ]);
        $agent->saveQuietly();

        Printer::create([
            'branch_id' => 7,
            'name' => 'Agent Printer',
            'connection_type' => 'spooler',
            'provider_type' => 'windows_agent',
            'options' => [
                'agent_id' => 'AGENT-ONE',
                'spooler_name' => 'POS-80',
                'copies' => 2,
                'timeout_ms' => 10000,
                'paper_size' => '80mm',
            ],
            'is_active' => true,
        ]);

        $service = $this->createMock(AgentPollServiceInterface::class);
        $controller = new AgentPollController($service);

        $request = Request::create('https://example.test/api/v1/agents/AGENT-ONE/setup', 'GET');

        $response = $controller->setup($request, 'AGENT-ONE');

        $this->assertSame(200, $response->status());
        $this->assertSame('Agent Printer', $response->getData(true)['assigned_printers'][0]['name']);
        $this->assertSame('spooler', $response->getData(true)['assigned_printers'][0]['connection_type']);
        $this->assertSame('POS-80', $response->getData(true)['assigned_printers'][0]['spooler_name']);
        $this->assertSame(2, $response->getData(true)['assigned_printers'][0]['copies']);
    }
}
