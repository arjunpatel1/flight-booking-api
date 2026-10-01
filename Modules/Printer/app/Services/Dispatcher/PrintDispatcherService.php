<?php

namespace Modules\Printer\Services\Dispatcher;

use Arr;
use Illuminate\Support\Facades\Log;
use Modules\Order\Models\Order;
use Modules\Printer\app\Factories\OrderResourceFactory;
use Modules\Printer\app\Factories\PrintContentFactory;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Services\PrinterAssignment\PrinterAssignmentResolver;

readonly class PrintDispatcherService implements PrintDispatcherServiceInterface
{
    /**
     * Create a new instance of PrintDispatcherService
     */
    public function __construct(
        private PrinterAssignmentResolver $assignmentResolver,
        private PrintDispatchRoutingSupport $routingSupport,
        private PrintJobWriter $jobWriter,
        private PrintRoutingFailureRecorder $failureRecorder,
    ) {}

    /** {@inheritDoc} */
    public function dispatchBill(Order $order, ?int $registerId = null): void
    {
        $this->dispatch($order, PrintContentType::Bill, $registerId);
    }

    /** {@inheritDoc} */
    public function dispatch(
        Order $order,
        PrintContentType $type,
        ?int $specificId = null,
        bool $retryDuplicate = false,
        array $options = []
    ): void {
        $dispatchStartedAt = microtime(true);
        $factory = PrintContentFactory::resolve($type);

        $order->load($factory->relations());
        $payloadStartedAt = microtime(true);
        $payload = $factory->resource($order);
        Log::debug('Print payload prepared.', [
            'order_id' => $order->id,
            'reference_no' => $order->reference_no,
            'branch_id' => $order->branch_id,
            'type' => $type->value,
            'specific_id' => $specificId,
            'duration_ms' => $this->durationMs($payloadStartedAt),
            'template' => 'print.templates.'.$type->value,
        ]);

        if ($type === PrintContentType::Kitchen && isset($options['prepared_payload']) && is_array($options['prepared_payload'])) {
            $payload = array_replace($payload, $options['prepared_payload']);
        }

        if ($type == PrintContentType::Kitchen) {
            $kitchenIds = collect($payload['kitchens'])->keys()->all();
            if (empty($kitchenIds)) {
                Log::warning('Kitchen print skipped: no kitchen payload resolved.', [
                    'order_id' => $order->id,
                    'reference_no' => $order->reference_no,
                    'branch_id' => $order->branch_id,
                ]);
            }

            $assignedPrinter = $this->assignmentResolver->resolve($order, $type);
            $printers = $assignedPrinter
                ? ['assigned' => $assignedPrinter]
                : $factory->printers(Arr::wrap($specificId ?: $kitchenIds));
            if (empty($printers)) {
                Log::warning('Kitchen print skipped: no kitchen printers resolved.', [
                    'order_id' => $order->id,
                    'reference_no' => $order->reference_no,
                    'branch_id' => $order->branch_id,
                    'kitchen_ids' => $kitchenIds,
                    'specific_id' => $specificId,
                ]);
            }

            $kitchens = $payload['kitchens'];
            unset($payload['kitchens']);
            $printerGroups = [];
            foreach ($printers as $userId => $printer) {
                $products = $assignedPrinter
                    ? collect($kitchens)->flatMap(fn ($kitchen) => collect($kitchen['products'] ?? []))->values()
                    : ($kitchens[$userId] ?? null);

                if ($assignedPrinter && collect($products)->isEmpty()) {
                    $products = collect($payload['products'] ?? [])->values();
                }

                if (collect($products)->isNotEmpty()) {
                    $physicalKey = $this->routingSupport->physicalPrinterKey($printer);
                    if (! isset($printerGroups[$physicalKey])) {
                        $printerGroups[$physicalKey] = [
                            'printer' => $printer,
                            'products' => collect(),
                            'kitchen_ids' => [],
                        ];
                    }

                    $printerGroups[$physicalKey]['products'] = $printerGroups[$physicalKey]['products']
                        ->merge(collect($products));
                    $printerGroups[$physicalKey]['kitchen_ids'][] = $userId;
                } else {
                    $this->failureRecorder->record(
                        $order,
                        $type,
                        'no_printable_products',
                        'Kitchen printer resolved, but no printable products matched this printer.',
                        $specificId,
                        [
                            'printer_id' => $printer->id,
                            'assigned_printer' => (bool) $assignedPrinter,
                            'kitchen_ids' => array_keys($kitchens),
                        ],
                    );
                    Log::warning('Kitchen print skipped: resolved printer has no printable products.', [
                        'order_id' => $order->id,
                        'reference_no' => $order->reference_no,
                        'branch_id' => $order->branch_id,
                        'printer_id' => $printer->id,
                        'type' => $type->value,
                        'assigned_printer' => (bool) $assignedPrinter,
                        'kitchen_ids' => array_keys($kitchens),
                    ]);
                }
            }

            foreach ($printerGroups as $physicalKey => $group) {
                $groupedProducts = collect($group['products'])
                    ->unique(fn ($product) => hash('sha256', json_encode($product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''))
                    ->values();

                Log::debug('Kitchen print grouped by physical printer target.', [
                    'order_id' => $order->id,
                    'reference_no' => $order->reference_no,
                    'branch_id' => $order->branch_id,
                    'printer_id' => $group['printer']->id,
                    'physical_key' => $physicalKey,
                    'kitchen_ids' => array_values(array_unique($group['kitchen_ids'])),
                    'product_count' => $groupedProducts->count(),
                ]);

                $this->jobWriter->create(
                    $group['printer'],
                    $order,
                    $type,
                    [...$payload, 'products' => $groupedProducts],
                    $retryDuplicate,
                    $specificId,
                );
            }

            $fallbackPrinted = false;

            if (empty($printers) && empty($specificId)) {
                $fallbackPrinter = $this->routingSupport->fallbackKitchenPrinter($order)
                    ?: $this->routingSupport->fallbackSingleActiveBranchPrinter($order, $type);
                $products = collect($kitchens)
                    ->flatMap(fn ($kitchen) => collect($kitchen['products'] ?? []))
                    ->values();

                if ($products->isEmpty()) {
                    $products = collect($payload['products'] ?? []);
                }

                if ($products->isEmpty() && ! isset($options['prepared_payload'])) {
                    $products = $order->products
                        ->map(fn ($product) => OrderResourceFactory::product($product, true))
                        ->values();
                }

                if ($fallbackPrinter && $products->isNotEmpty()) {
                    Log::info('Kitchen print using POS register fallback printer.', [
                        'order_id' => $order->id,
                        'reference_no' => $order->reference_no,
                        'branch_id' => $order->branch_id,
                        'printer_id' => $fallbackPrinter->id,
                        'register_id' => $order->pos_register_id,
                    ]);

                    $this->jobWriter->create($fallbackPrinter, $order, $type, [...$payload, 'products' => $products], $retryDuplicate, $specificId);
                    $fallbackPrinted = true;
                }
            }

            if (empty($printers) && ! $fallbackPrinted) {
                $this->failureRecorder->record(
                    $order,
                    $type,
                    'no_kitchen_printer',
                    $this->shouldFailIfUnroutable($options)
                        ? 'No kitchen printer is configured for this order.'
                        : 'No kitchen printer route matched this order.',
                    $specificId,
                    [
                        'kitchen_ids' => $kitchenIds,
                        'register_id' => $order->pos_register_id,
                    ],
                );
            }

            if (empty($printers) && ! $fallbackPrinted && $this->shouldFailIfUnroutable($options)) {
                abort(422, 'No kitchen printer is configured for this order.');
            }
        } else {
            $printer = $this->assignmentResolver->resolve($order, $type)
                ?: $factory->printers($specificId ?: $order->pos_register_id)
                ?: $this->routingSupport->fallbackSingleActiveBranchPrinter($order, $type);
            if ($printer) {
                $this->jobWriter->create($printer, $order, $type, $payload, $retryDuplicate, $specificId);
            } else {
                Log::warning('Print skipped: no printer resolved.', [
                    'order_id' => $order->id,
                    'reference_no' => $order->reference_no,
                    'branch_id' => $order->branch_id,
                    'type' => $type->value,
                    'specific_id' => $specificId,
                    'register_id' => $order->pos_register_id,
                ]);

                $this->failureRecorder->record(
                    $order,
                    $type,
                    'no_printer',
                    'No printer is configured for '.$type->value.' print.',
                    $specificId,
                    [
                        'register_id' => $order->pos_register_id,
                    ],
                );

                if ($this->shouldFailIfUnroutable($options)) {
                    abort(422, 'No printer is configured for '.$type->value.' print.');
                }
            }
        }

        Log::debug('Print dispatch timing completed.', [
            'order_id' => $order->id,
            'reference_no' => $order->reference_no,
            'branch_id' => $order->branch_id,
            'type' => $type->value,
            'specific_id' => $specificId,
            'duration_ms' => $this->durationMs($dispatchStartedAt),
        ]);
    }

    private function shouldFailIfUnroutable(array $options): bool
    {
        return (bool) ($options['fail_if_unroutable'] ?? false);
    }

    /** {@inheritDoc} */
    public function dispatchInvoice(Order $order, ?int $registerId = null): void
    {
        $this->dispatch($order, PrintContentType::Invoice, $registerId);
    }

    /** {@inheritDoc} */
    public function dispatchDelivery(Order $order, ?int $registerId = null): void
    {
        $this->dispatch($order, PrintContentType::Delivery, $registerId);
    }

    /** {@inheritDoc} */
    public function dispatchWaiter(Order $order, ?int $registerId = null): void
    {
        $this->dispatch($order, PrintContentType::Waiter, $registerId);
    }

    /** {@inheritDoc} */
    public function dispatchKitchens(Order $order, ?int $kitchenId = null): void
    {
        $this->dispatch($order, PrintContentType::Kitchen, $kitchenId);
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
