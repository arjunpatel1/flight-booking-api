<?php

namespace Tests\Unit\Printer;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Modules\Printer\Events\PrintJobCreated;
use Modules\Printer\Http\Controllers\Api\V1\AgentPollController;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Services\AgentPoll\AgentPollServiceInterface;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class AgentPollControllerTestPrintTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('print_agents');
        Schema::dropIfExists('print_jobs');

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

        Schema::create('print_jobs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('branch_id');
            $table->json('printer_config');
            $table->text('rendered_bytes');
            $table->string('status');
            $table->string('deduplication_key')->nullable();
            $table->string('claimed_by')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_test_print_creates_print_job_and_broadcasts_reverb_event(): void
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

        // Fake only the domain event. Faking every event also suppresses the
        // PrintJob model's creating hook, which is responsible for its UUID.
        Event::fake([PrintJobCreated::class]);

        $agent = PrintAgent::create([
            'branch_id' => 7,
            'agent_id' => 'AGENT-ONE',
            'secret' => 'secret-value',
            'name' => 'Test Agent',
            'is_active' => true,
        ]);

        $service = $this->createMock(AgentPollServiceInterface::class);
        $controller = new AgentPollController($service);

        $request = Request::create('/api/v1/agents/AGENT-ONE/test-print', 'POST', [
            'printer_name' => 'POS-80',
        ]);
        $request->agent = $agent;

        $response = $controller->testPrint($request);

        $this->assertSame(200, $response->status());
        $payload = $response->getData(true);
        $this->assertTrue($payload['success']);
        $this->assertSame('reverb', $payload['transport']);
        $this->assertArrayHasKey('job_id', $payload);

        Event::assertDispatched(PrintJobCreated::class, function (PrintJobCreated $event) use ($agent) {
            return $event->targetAgentId === $agent->agent_id
                && data_get($event->job->printer_config, 'agent_id') === $agent->agent_id
                && $event->printType === 'test';
        });
    }
}
