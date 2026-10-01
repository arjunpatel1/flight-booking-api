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
class AgentPollControllerVerifyTest extends TestCase
{
    public function test_verify_returns_reverb_setup_and_transport(): void
    {
        $service = $this->createMock(AgentPollServiceInterface::class);
        $controller = new AgentPollController($service);

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
            'agent_id' => 'AGENT-ONE',
            'branch_id' => 7,
            'secret' => 'secret-value',
            'is_active' => true,
            'last_seen_at' => null,
        ]);

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

        Printer::create([
            'branch_id' => 7,
            'name' => 'Agent Printer',
            'connection_type' => 'spooler',
            'provider_type' => 'windows_agent',
            'options' => [
                'agent_id' => 'AGENT-ONE',
                'spooler_name' => 'POS-80',
                'copies' => 1,
                'timeout_ms' => 10000,
                'paper_size' => '80mm',
            ],
            'is_active' => true,
        ]);

        $request = Request::create('/api/v1/agents/AGENT-ONE/verify', 'POST');
        $request->agent = $agent;

        $response = $controller->verify($request);

        $this->assertSame(200, $response->status());
        $this->assertSame([
            'success' => true,
            'agent_id' => 'AGENT-ONE',
            'branch_id' => '7',
            'is_active' => true,
            'last_seen_at' => null,
            'transport' => 'reverb',
            'reverb' => [
                'app_key' => 'REVERB-KEY',
                'socket_url' => 'ws://127.0.0.1:8080/app/REVERB-KEY?protocol=7&client=nexdine-print-agent&version=1.0.0&flash=false',
            ],
            'assigned_printers' => [
                [
                    'id' => 1,
                    'name' => 'Agent Printer',
                    'connection_type' => 'spooler',
                    'host' => null,
                    'port' => null,
                    'spooler_name' => 'POS-80',
                    'paper_size' => '80mm',
                    'copies' => 1,
                ],
            ],
        ], $response->getData(true));
    }
}
