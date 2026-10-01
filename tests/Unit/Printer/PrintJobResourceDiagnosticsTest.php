<?php

namespace Tests\Unit\Printer;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Models\PrintJob;
use Modules\Printer\Transformers\Api\V1\PrintJobResource;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class PrintJobResourceDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_routing_job_exposes_support_diagnostics(): void
    {
        $job = new PrintJob([
            'id' => 'job-failed-routing',
            'branch_id' => 7,
            'status' => PrintJobStatus::Failed,
            'error_message' => 'No printer is configured for bill print.',
            'printer_config' => [
                'type' => 'unrouted',
                'connection' => ['name' => 'Not routed'],
                'settings' => ['media' => '80mm', 'copies' => 0],
                'diagnostics' => [
                    'print_type' => 'bill',
                    'order_id' => 31,
                    'reference_no' => '#31',
                    'stage' => 'no_printer',
                    'message' => 'No printer is configured for bill print.',
                    'checks' => [
                        [
                            'key' => 'route',
                            'label' => 'Printer route',
                            'status' => 'failed',
                            'message' => 'No printer route matched assignment, register or branch fallback.',
                        ],
                    ],
                ],
            ],
        ]);

        $payload = (new PrintJobResource($job))->toArray(Request::create('/'));

        $this->assertSame('bill', $payload['print_type']);
        $this->assertSame(31, $payload['order']['id']);
        $this->assertSame('#31', $payload['order']['reference_no']);
        $this->assertSame('No printer is configured for bill print.', $payload['failure_reason']);
        $this->assertSame('failed', $payload['pipeline'][0]['status']);
        $this->assertSame('Printer route', $payload['pipeline'][0]['label']);
        $this->assertSame('delivery', $payload['pipeline'][1]['key']);
        $this->assertSame('failed', $payload['pipeline'][1]['status']);
        $this->assertSame('No printer is configured for bill print.', $payload['pipeline'][1]['message']);
    }
}
