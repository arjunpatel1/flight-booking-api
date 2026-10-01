<?php

namespace Modules\Invoice\Services\CreateCreditNote;

use Illuminate\Support\Facades\DB;
use Modules\Invoice\Enums\InvoiceKind;
use Modules\Invoice\Enums\InvoicePurpose;
use Modules\Invoice\Models\Invoice;
use Modules\Invoice\Services\DiscountBuilder\DiscountBuilderServiceInterface;
use Modules\Invoice\Services\InvoiceLineBuilder\InvoiceLineBuilderServiceInterface;
use Modules\Invoice\Services\InvoiceNumberGenerator\InvoiceNumberGeneratorServiceInterface;
use Modules\Invoice\Services\InvoicePartyBuilder\InvoicePartyBuilderServiceInterface;
use Modules\Invoice\Services\InvoiceTotalsCalculator\InvoiceTotalsCalculatorServiceInterface;
use Modules\Invoice\Services\TaxBuilder\TaxBuilderServiceInterface;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Models\Order;

class CreateCreditNoteService implements CreateCreditNoteServiceInterface
{
    public function __construct(
        protected InvoiceNumberGeneratorServiceInterface  $numberGenerator,
        protected InvoicePartyBuilderServiceInterface     $partyBuilder,
        protected InvoiceLineBuilderServiceInterface      $lineBuilder,
        protected InvoiceTotalsCalculatorServiceInterface $totalsCalculator,
        protected TaxBuilderServiceInterface              $taxBuilder,
        protected DiscountBuilderServiceInterface         $discountBuilder,
    )
    {
    }

    /** @inheritDoc */
    public function create(Order $order, InvoicePurpose $purpose = InvoicePurpose::Return): ?Invoice
    {
        return DB::transaction(function () use ($order, $purpose) {

            $original = $this->getOriginalInvoice($order);

            if (is_null($original)) {
                return null;
            }

            $order->load([
                "customer",
                "branch",
                "taxes.tax",
                "products" => fn($query) => $query
                    ->whereIn("status", [OrderProductStatus::Cancelled, OrderProductStatus::Refunded])
                    ->with(["taxes.tax"])
                    ->without(["options"]),
                "payments",
                "discount"
            ]);

            $seller = $this->partyBuilder->createSeller($order->branch);
            $buyer = $this->partyBuilder->createBuyer($order->customer);

            $numberData = $this->numberGenerator->generate($order->branch, "CN");

            $creditNote = Invoice::query()->create([
                'order_id' => $order->id,
                'table_merge_id' => $order->table_merge_id,
                'branch_id' => $order->branch_id,
                'seller_party_id' => $seller->id,
                'buyer_party_id' => $buyer?->id,
                'type' => $original->type,
                'purpose' => $purpose,
                'invoice_kind' => InvoiceKind::CreditNote,
                'reference_invoice_id' => $original->id,
                'currency' => $order->currency,
                'currency_rate' => $order->currency_rate,
                'invoice_number' => $numberData['number'],
                'invoice_counter' => $numberData['counter'],
                'subtotal' => 0,
                'tax_total' => 0,
                'discount_total' => 0,
                'total' => 0,
                'issued_at' => now(),
            ]);

            $orders = collect([$order]);

            $lines = $this->lineBuilder->createLines($creditNote, $orders);
            $this->taxBuilder->createInvoiceTaxes($creditNote, $orders);
            $this->discountBuilder->createDiscounts($creditNote, $orders);
            $this->totalsCalculator->applyTotals($creditNote, $lines);

            return $creditNote;
        });
    }

    /**
     * Get original invoice
     *
     * @param Order $order
     * @return Invoice|null
     */
    private function getOriginalInvoice(Order $order): ?Invoice
    {
        if ($order->table_merge_id) {
            return Invoice::query()
                ->where('table_merge_id', $order->table_merge_id)
                ->where('invoice_kind', InvoiceKind::Standard)
                ->where('purpose', InvoicePurpose::Original)
                ->latest('id')
                ->first();
        }

        return Invoice::query()
            ->where('order_id', $order->id)
            ->where('invoice_kind', InvoiceKind::Standard)
            ->where('purpose', InvoicePurpose::Original)
            ->latest('id')
            ->first();
    }
}
