<?php

namespace Tests\Unit\Printer;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Modules\Printer\Http\Middleware\ValidateAgentSignature;
use Modules\Printer\Models\PrintAgent;
use Tests\TestCase;

class ValidateAgentSignatureMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('print_agents');
        Schema::create('print_agents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('agent_id')->unique();
            $table->text('secret');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
        Schema::enableForeignKeyConstraints();
    }

    public function test_handle_allows_valid_agent_signature(): void
    {
        $agent = PrintAgent::create([
            'branch_id' => 7,
            'agent_id' => 'AGENT-ONE',
            'name' => 'Test Agent',
            'secret' => 'secret-value',
            'is_active' => true,
        ]);
        $agent->forceFill(['secret' => 'secret-value'])->save();

        $payload = ['socket_id' => '123.456', 'channel_name' => 'private-agent.AGENT-ONE'];
        $rawBody = json_encode($payload);
        $signature = hash_hmac('sha256', "{$agent->agent_id}:{$rawBody}", 'secret-value');

        $request = Request::create('/api/v1/agents/AGENT-ONE/poll', 'POST', [], [], [], [], $rawBody);
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('X-Agent-ID', $agent->agent_id);
        $request->headers->set('X-Signature', $signature);

        $middleware = new ValidateAgentSignature();
        $response = $middleware->handle($request, fn($req) => new Response($req->agent->agent_id));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('AGENT-ONE', $response->getContent());
    }

    public function test_handle_rejects_invalid_signature(): void
    {
        PrintAgent::create([
            'branch_id' => 7,
            'agent_id' => 'AGENT-ONE',
            'name' => 'Test Agent',
            'secret' => 'secret-value',
            'is_active' => true,
        ]);

        $payload = ['socket_id' => '123.456', 'channel_name' => 'private-agent.AGENT-ONE'];
        $rawBody = json_encode($payload);

        $request = Request::create('/api/v1/agents/AGENT-ONE/poll', 'POST', [], [], [], [], $rawBody);
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('X-Agent-ID', 'AGENT-ONE');
        $request->headers->set('X-Signature', 'invalid-signature');

        $middleware = new ValidateAgentSignature();
        $response = $middleware->handle($request, fn($req) => new Response('passed'));

        $this->assertSame(401, $response->status());
        $this->assertSame(['error' => 'Invalid signature'], $response->getData(true));
    }
}
