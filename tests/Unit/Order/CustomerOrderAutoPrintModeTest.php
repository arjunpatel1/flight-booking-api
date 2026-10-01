<?php

namespace Tests\Unit\Order;

use Illuminate\Support\Facades\Bus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Events\OrderPaid;
use Modules\Order\Listeners\PrintKitchenTicket;
use Modules\Order\Models\Order;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Jobs\DispatchPrintJob;
use Modules\Setting\Repositories\SettingRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerOrderAutoPrintModeTest extends TestCase
{
    #[DataProvider('modes')]
    public function test_customer_order_print_mode_dispatches_selected_documents(
        string $mode,
        array $expectedTypes,
    ): void {
        Bus::fake();
        app()->instance('setting', new SettingRepository(collect([
            'customer_order_auto_print_mode' => $mode,
        ])));

        $order = new Order;
        $order->forceFill(['id' => 9123, 'status' => OrderStatus::Confirmed]);

        app(PrintKitchenTicket::class)->handle(new OrderCreated($order));

        foreach ([PrintContentType::Kitchen, PrintContentType::Invoice] as $type) {
            $assert = in_array($type, $expectedTypes, true) ? 'assertDispatchedSync' : 'assertNotDispatched';
            Bus::{$assert}(DispatchPrintJob::class, fn (DispatchPrintJob $job): bool => $job->orderId === 9123 && $job->type === $type
            );
        }
    }

    public static function modes(): array
    {
        return [
            'disabled' => ['disabled', []],
            'KOT' => ['kot', [PrintContentType::Kitchen]],
            'KOT and invoice' => ['kot_invoice', [PrintContentType::Kitchen]],
            'invoice' => ['invoice', []],
        ];
    }

    #[DataProvider('paidModes')]
    public function test_customer_invoice_print_waits_until_payment(string $mode, bool $expected): void
    {
        Bus::fake();
        app()->instance('setting', new SettingRepository(collect([
            'customer_order_auto_print_mode' => $mode,
        ])));

        $order = new Order;
        $order->forceFill(['id' => 9124, 'status' => OrderStatus::Confirmed]);

        app(PrintKitchenTicket::class)->handle(new OrderPaid($order));

        if ($expected) {
            Bus::assertDispatchedSync(DispatchPrintJob::class, fn (DispatchPrintJob $job): bool => $job->orderId === 9124 && $job->type === PrintContentType::Invoice
            );
        } else {
            Bus::assertNotDispatched(DispatchPrintJob::class);
        }
    }

    public static function paidModes(): array
    {
        return [
            'disabled' => ['disabled', false],
            'KOT' => ['kot', false],
            'KOT and invoice' => ['kot_invoice', true],
            'invoice' => ['invoice', true],
        ];
    }
}
