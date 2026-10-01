<?php

namespace Modules\Printer\Factories\PrintContents;

use Modules\Currency\Currency;
use Modules\Invoice\Models\InvoiceDiscount;
use Modules\Invoice\Models\InvoiceLine;
use Modules\Invoice\Models\InvoiceTax;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Payment\Models\PaymentAllocation;
use Modules\Pos\Models\PosRegister;
use Modules\Printer\app\Factories\OrderResourceFactory;
use Modules\Printer\Contracts\PrintContentFactoryInterface;
use Modules\Printer\Models\Printer;
use Modules\Support\Enums\DateTimeFormat;
use Illuminate\Support\Facades\Log;

class PrintInvoiceContentFactory implements PrintContentFactoryInterface
{

    /** @inheritDoc */
    public function relations(): array
    {
        return [
        ];
    }

    /** @inheritDoc */
    public function resource(Order $order): array
    {
        $payloadStartedAt = microtime(true);
        $order->loadMissing('table:id,name');

        $invoice = $order->getInvoice([
            "seller",
            "buyer",
            "discounts",
            "taxes",
            "allocations" => fn($query) => $query->with(["payment"]),
            "lines",
            "branch:id,name,address_line1,address_line2,phone,vat_tin",
        ]);

        abort_if(is_null($invoice), 422, __('core::errors.not_found'));

        $payload = [
            "id" => $invoice->id,
            "invoice_number" => $invoice->invoice_number,
            "uuid" => $invoice->uuid,
            "branch" => [
                "id" => $invoice->branch_id,
                "name" => $invoice->branch?->name,
                "address_line1" => $invoice->branch?->address_line1,
                "address_line2" => $invoice->branch?->address_line2,
                "phone" => $invoice->branch?->phone,
                "tax_number" => $invoice->branch?->vat_tin,
                "logo" => getInvoiceLogoBase64(),
            ],
            "order" => OrderResourceFactory::order($order),
            "table" => $order->type === OrderType::DineIn && $order->table
                ? OrderResourceFactory::table($order->table)
                : null,
            "seller" => OrderResourceFactory::invoiceParty($invoice->seller),
            "buyer" => OrderResourceFactory::invoiceParty($invoice->buyer),
            "type" => $invoice->type->trans(),
            "subtotal" => $invoice->subtotal->round()->amount(),
            "tax_total" => $invoice->tax_total->round()->amount(),
            "discount_total" => $invoice->discount_total->round()->amount(),
            "total" => $invoice->total->round()->amount(),
            "paid_amount" => $invoice->paid_amount->round()->amount(),
            "refunded_amount" => $invoice->refunded_amount->round()->amount(),
            "net_paid" => $invoice->net_paid->round()->amount(),
            "currency_subunit" => Currency::subunit($invoice->currency),
            "issued_at" => [
                "full_date" => dateTimeFormat($invoice->issued_at),
                "date" => dateTimeFormat($invoice->issued_at, DateTimeFormat::Date),
                "time" => dateTimeFormat($invoice->issued_at, DateTimeFormat::Time),
            ],
            "discounts" => $invoice->discounts->map(fn(InvoiceDiscount $discount) => OrderResourceFactory::invoiceDiscount($discount)),
            "taxes" => $invoice->taxes->map(fn(InvoiceTax $tax) => OrderResourceFactory::invoiceTax($tax)),
            "allocations" => $invoice->allocations->map(fn(PaymentAllocation $allocation) => OrderResourceFactory::paymentAllocation($allocation)),
            "lines" => $invoice->lines->map(fn(InvoiceLine $line) => OrderResourceFactory::invoiceLine($line)),
        ];

        Log::info('Thermal invoice payload prepared.', [
            'order_id' => $order->id,
            'reference_no' => $order->reference_no,
            'invoice_id' => $invoice->id,
            'duration_ms' => (int) round((microtime(true) - $payloadStartedAt) * 1000),
            'payload_bytes' => strlen(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'line_count' => $invoice->lines->count(),
        ]);

        return $payload;
    }


    /** @inheritDoc */
    public function printers(int|array $specificIds): array|Printer|null
    {
        $register = PosRegister::query()
            ->with("invoicePrinter")
            ->where('id', $specificIds)
            ->first();

        return $register?->invoicePrinter;
    }
}
