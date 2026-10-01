<?php

namespace Modules\Printer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Order\Models\Order;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Services\Dispatcher\PrintDispatcherServiceInterface;
use Throwable;

class DispatchPrintJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    // Exponential backoff: 5s → 15s → 30s between attempts.
    // Prevents rapid retry storms when the print renderer or DB has a transient hiccup.
    public array $backoff = [5, 15, 30];

    public int $queuedAtMs;

    public ?string $requestId;

    public function __construct(
        public readonly int $orderId,
        public readonly PrintContentType $type,
        public readonly ?int $specificId = null,
        public readonly bool $retryDuplicate = false,
        public readonly array $options = [],
    ) {
        $this->queuedAtMs = (int) floor(microtime(true) * 1000);
        $this->requestId = request()?->headers->get('X-Request-Id');
        $this->onQueue(config('printer.queue.high_priority', 'default'));
        $this->afterCommit();
    }

    public static function dispatchAfterCommit(
        int $orderId,
        PrintContentType $type,
        ?int $specificId = null,
        bool $retryDuplicate = false,
        array $options = [],
    ): void {
        $callback = fn () => self::dispatch($orderId, $type, $specificId, $retryDuplicate, $options);

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);
            return;
        }

        $callback();
    }

    public static function dispatchSyncAfterCommit(
        int $orderId,
        PrintContentType $type,
        ?int $specificId = null,
        bool $retryDuplicate = false,
        array $options = [],
        bool $failOnError = false,
    ): void {
        $callback = function () use ($orderId, $type, $specificId, $retryDuplicate, $options, $failOnError): void {
            try {
                self::dispatchSync($orderId, $type, $specificId, $retryDuplicate, $options);
            } catch (Throwable $exception) {
                Log::error('Immediate print dispatch failed.', [
                    'order_id' => $orderId,
                    'type' => $type->value,
                    'specific_id' => $specificId,
                    'retry_duplicate' => $retryDuplicate,
                    'options' => array_keys($options),
                    'error' => $exception->getMessage(),
                ]);

                if ($failOnError) {
                    throw $exception;
                }
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);
            return;
        }

        $callback();
    }

    public function handle(PrintDispatcherServiceInterface $dispatcher): void
    {
        $order = Order::query()->findOrFail($this->orderId);

        Log::info('Print dispatch started.', [
            'order_id' => $order->id,
            'reference_no' => $order->reference_no,
            'type' => $this->type->value,
            'specific_id' => $this->specificId,
            'retry_duplicate' => $this->retryDuplicate,
            'options' => array_keys($this->options),
            'queue_wait_ms' => max(0, (int) floor(microtime(true) * 1000) - $this->queuedAtMs),
            'request_id' => $this->requestId,
        ]);

        try {
            $dispatcher->dispatch($order, $this->type, $this->specificId, $this->retryDuplicate, $this->options);
        } catch (Throwable $exception) {
            Log::error('Print dispatch failed.', [
                'order_id' => $order->id,
                'reference_no' => $order->reference_no,
                'type' => $this->type->value,
                'specific_id' => $this->specificId,
                'retry_duplicate' => $this->retryDuplicate,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        Log::info('Print dispatch finished.', [
            'order_id' => $order->id,
            'reference_no' => $order->reference_no,
            'type' => $this->type->value,
            'specific_id' => $this->specificId,
            'retry_duplicate' => $this->retryDuplicate,
        ]);
    }
}
