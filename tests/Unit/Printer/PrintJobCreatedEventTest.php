<?php

namespace Tests\Unit\Printer;

use Modules\Printer\Events\PrintJobCreated;
use Modules\Printer\Models\PrintJob;
use Tests\TestCase;

class PrintJobCreatedEventTest extends TestCase
{
    public function test_print_job_created_broadcasts_only_to_assigned_agent_channel(): void
    {
        $job = new PrintJob([
            'id' => 'job-123',
            'branch_id' => 7,
            'printer_config' => [
                'agent_id' => 'AGENT-ONE',
                'type' => 'spooler',
            ],
        ]);

        $event = new PrintJobCreated($job, 44, 'bill');
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame('private-agent.AGENT-ONE', (string) $channels[0]);
        $this->assertSame('print.job.created', $event->broadcastAs());
        $payload = $event->broadcastWith();

        $this->assertSame([
            'job_id' => 'job-123',
            'branch_id' => 7,
            'printer_id' => 44,
            'printer_type' => 'spooler',
            'agent_id' => 'AGENT-ONE',
            'print_type' => 'bill',
        ], $payload['payload']);

        $this->assertSame('print.job.created', $payload['event_name']);
        $this->assertSame('print_job', $payload['entity']);
        $this->assertSame('job-123', $payload['entity_id']);
        $this->assertSame('job-123', $payload['job_id']);
        $this->assertSame(7, $payload['branch_id']);
        $this->assertSame(44, $payload['printer_id']);
        $this->assertSame('AGENT-ONE', $payload['agent_id']);
        $this->assertSame('bill', $payload['print_type']);
    }
}
