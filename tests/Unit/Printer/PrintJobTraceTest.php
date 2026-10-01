<?php

namespace Tests\Unit\Printer;

use Modules\Order\Models\Order;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Services\Diagnostics\PrintJobTrace;
use Tests\TestCase;

class PrintJobTraceTest extends TestCase
{
    public function test_trace_builds_and_appends_support_timeline(): void
    {
        $order = (new Order)->forceFill([
            'id' => 31,
            'reference_no' => '#31',
            'branch_id' => 7,
        ]);

        $trace = PrintJobTrace::build(
            $order,
            PrintContentType::Bill,
            'queued',
            'Print job created and waiting for agent pickup.',
            [
                'printer_id' => 12,
                'printer_name' => 'POS-80',
                'agent_id' => 'agent-1',
            ],
        );

        $this->assertSame('queued', $trace['stage']);
        $this->assertSame('bill', $trace['print_type']);
        $this->assertSame('#31', $trace['reference_no']);
        $this->assertSame('queued', $trace['timeline'][0]['stage']);
        $this->assertSame('Printer route', $trace['checks'][1]['label']);

        $config = PrintJobTrace::appendToConfig(
            ['diagnostics' => $trace],
            'claimed',
            'Agent picked up this print job.',
            ['agent_id' => 'agent-1'],
        );

        $diagnostics = $config['diagnostics'];
        $this->assertSame('claimed', $diagnostics['stage']);
        $this->assertCount(2, $diagnostics['timeline']);
        $this->assertSame('claimed', $diagnostics['timeline'][1]['stage']);
        $this->assertSame('ok', $this->checkByKey($diagnostics['checks'], 'queue')['status']);
        $this->assertStringContainsString(
            'agent-1',
            $this->checkByKey($diagnostics['checks'], 'agent')['message'],
        );
    }

    private function checkByKey(array $checks, string $key): array
    {
        foreach ($checks as $check) {
            if (($check['key'] ?? null) === $key) {
                return $check;
            }
        }

        $this->fail("Missing check {$key}");
    }
}
