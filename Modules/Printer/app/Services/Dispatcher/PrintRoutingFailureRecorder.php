<?php

namespace Modules\Printer\Services\Dispatcher;

use Illuminate\Support\Facades\Log;
use Modules\Order\Models\Order;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Enum\PrinterPaperSize;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Models\PrintJob;
use Modules\Printer\Services\Diagnostics\PrintJobTrace;
use Throwable;

class PrintRoutingFailureRecorder
{
    public function record(
        Order $order,
        PrintContentType $type,
        string $reasonCode,
        string $message,
        ?int $specificId = null,
        array $context = [],
    ): void {
        $attemptScope = trim((string) request()?->header('Idempotency-Key'));
        if ($attemptScope === '') {
            $attemptScope = now()->format('YmdHi');
        }

        $deduplicationKey = hash('sha256', implode('|', [
            'print-routing-failure',
            $order->branch_id,
            $order->id,
            $type->value,
            $specificId ?: '',
            $reasonCode,
            $attemptScope,
        ]));

        $diagnostics = PrintJobTrace::build(
            $order,
            $type,
            $reasonCode,
            $message,
            [
                ...$context,
                'specific_id' => $specificId,
            ],
        );

        try {
            PrintJob::query()->updateOrCreate(
                ['deduplication_key' => $deduplicationKey],
                [
                    'branch_id' => $order->branch_id,
                    'printer_config' => [
                        'type' => 'unrouted',
                        'connection' => [
                            'name' => 'Not routed',
                            'host' => null,
                        ],
                        'settings' => [
                            'media' => PrinterPaperSize::Paper80mm->value,
                            'copies' => 0,
                        ],
                        'diagnostics' => $diagnostics,
                    ],
                    'rendered_bytes' => '',
                    'status' => PrintJobStatus::Failed,
                    'error_message' => $message,
                    'completed_at' => now(),
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('Unable to record failed print routing diagnostic.', [
                'order_id' => $order->id,
                'reference_no' => $order->reference_no,
                'branch_id' => $order->branch_id,
                'type' => $type->value,
                'reason_code' => $reasonCode,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
