<?php

namespace Modules\Invoice\Services\CreateInvoice;


use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Invoice\Enums\InvoiceKind;
use Modules\Invoice\Enums\InvoicePurpose;
use Modules\Invoice\Enums\InvoiceType;
use Modules\Invoice\Models\Invoice;
use Modules\Invoice\Services\DiscountBuilder\DiscountBuilderServiceInterface;
use Modules\Invoice\Services\InvoiceLineBuilder\InvoiceLineBuilderServiceInterface;
use Modules\Invoice\Services\InvoiceNumberGenerator\InvoiceNumberGeneratorServiceInterface;
use Modules\Invoice\Services\InvoicePartyBuilder\InvoicePartyBuilderServiceInterface;
use Modules\Invoice\Services\InvoiceTotalsCalculator\InvoiceTotalsCalculatorServiceInterface;
use Modules\Invoice\Services\PaymentAllocation\PaymentAllocationServiceInterface;
use Modules\Invoice\Services\TaxBuilder\TaxBuilderServiceInterface;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Models\Order;

class CreateInvoiceService implements CreateInvoiceServiceInterface
{
    /**
     * Create a new CreateInvoiceService
     *
     * @param InvoiceNumberGeneratorServiceInterface $numberGenerator
     * @param InvoicePartyBuilderServiceInterface $partyBuilder
     * @param InvoiceLineBuilderServiceInterface $lineBuilder
     * @param InvoiceTotalsCalculatorServiceInterface $totalsCalculator
     * @param TaxBuilderServiceInterface $taxBuilder
     * @param DiscountBuilderServiceInterface $discountBuilder
     * @param PaymentAllocationServiceInterface $paymentAllocator
     */
    public function __construct(
        protected InvoiceNumberGeneratorServiceInterface  $numberGenerator,
        protected InvoicePartyBuilderServiceInterface     $partyBuilder,
        protected InvoiceLineBuilderServiceInterface      $lineBuilder,
        protected InvoiceTotalsCalculatorServiceInterface $totalsCalculator,
        protected TaxBuilderServiceInterface              $taxBuilder,
        protected DiscountBuilderServiceInterface         $discountBuilder,
        protected PaymentAllocationServiceInterface       $paymentAllocator
    )
    {
    }

    /** @inheritDoc */
    public function create(Order $order, InvoicePurpose $purpose = InvoicePurpose::Original): Invoice
    {
        return DB::transaction(function () use ($order, $purpose) {
            $existingInvoice = Invoice::query()
                ->where('purpose', $purpose->value)
                ->when(
                    $order->table_merge_id,
                    fn ($query, $tableMergeId) => $query->where('table_merge_id', $tableMergeId),
                    fn ($query) => $query->where('order_id', $order->id)
                )
                ->lockForUpdate()
                ->first();

            // Payment requests are idempotent, but an event may still be retried
            // after a response or queue failure. Never issue a second original
            // invoice for the same order/merged bill.
            if ($existingInvoice) {
                return $existingInvoice;
            }

            $orders = $this->getMergedOrders($order);

            $seller = $this->partyBuilder->createSeller($order->branch);
            $buyer = $this->partyBuilder->createBuyer($order->customer);

            $numberData = $this->numberGenerator->generate($order->branch);

            $invoice = Invoice::query()
                ->create([
                    'order_id' => $order->id,
                    'table_merge_id' => $order->table_merge_id,
                    'branch_id' => $order->branch_id,
                    'seller_party_id' => $seller->id,
                    'buyer_party_id' => $buyer?->id,
                    'type' => InvoiceType::Simplified,
                    'purpose' => $purpose,
                    'invoice_kind' => InvoiceKind::Standard,
                    'currency' => $order->currency,
                    'currency_rate' => $order->currency_rate,
                    'invoice_number' => $numberData['number'],
                    'invoice_counter' => $numberData['counter'],
                    'subtotal' => 0,
                    'tax_total' => 0,
                    'discount_total' => 0,
                    'total' => 0,
                    'issued_at' => now()
                ]);

            $lines = $this->lineBuilder->createLines($invoice, $orders);
            $this->taxBuilder->createInvoiceTaxes($invoice, $orders);
            $this->discountBuilder->createDiscounts($invoice, $orders);
            $this->totalsCalculator->applyTotals($invoice, $lines);
            $this->paymentAllocator->allocate($invoice, $orders);

            return $invoice;
        });
    }

    /**
     * Get merged orders
     *
     * @param Order $order
     * @return Collection<Order>
     */
    private function getMergedOrders(Order $order): Collection
    {
        $with = [
            "customer",
            "branch",
            "taxes.tax",
            "products" => fn($query) => $query
                ->whereNotIn("status", [OrderProductStatus::Cancelled, OrderProductStatus::Refunded])
                ->with(["taxes.tax"])
                ->without(["options"]),
            "payments",
            "discount"
        ];

        if (!$order->table_merge_id) {
            return collect([$order->load($with)]);
        }

        return Order::query()
            ->with($with)
            ->where('table_merge_id', $order->table_merge_id)
            ->get();
    }
}
