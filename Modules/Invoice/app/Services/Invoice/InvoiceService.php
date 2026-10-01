<?php

namespace Modules\Invoice\Services\Invoice;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Invoice\Enums\InvoiceKind;
use Modules\Invoice\Enums\InvoicePurpose;
use Modules\Invoice\Enums\InvoiceStatus;
use Modules\Invoice\Enums\InvoiceType;
use Modules\Invoice\Models\Invoice;
use Modules\Support\Money;
use Modules\Support\GlobalStructureFilters;
use Modules\Payment\Services\PaymentAggregationService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceService implements InvoiceServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("invoice::invoices.invoice");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->query($filters)
            ->sortBy($sorts)
            ->with([
                "seller:id,legal_name",
                "buyer:id,legal_name",
                "branch:id,name",
                "referenceInvoice:id,invoice_number,uuid"
            ])
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    public function summary(array $filters = []): array
    {
        $base = $this->query($filters);
        $totals = (clone $base)
            ->toBase()
            ->selectRaw('COUNT(*) AS count')
            ->selectRaw('SUM(total * COALESCE(currency_rate, 1)) AS total')
            ->selectRaw('SUM(tax_total * COALESCE(currency_rate, 1)) AS tax_total')
            ->selectRaw('SUM(discount_total * COALESCE(currency_rate, 1)) AS discount_total')
            ->selectRaw('SUM(net_paid * COALESCE(currency_rate, 1)) AS net_paid')
            ->first();

        $creditNotes = (clone $base)
            ->where('invoice_kind', InvoiceKind::CreditNote)
            ->count();

        $unpaid = (clone $base)
            ->whereRaw('net_paid <= 0')
            ->count();

        $partial = (clone $base)
            ->whereRaw('net_paid > 0 AND net_paid < total')
            ->count();

        $collections = app(PaymentAggregationService::class)->summarize($filters);

        return [
            'count' => (int) ($totals?->count ?? 0),
            'total' => Money::inDefaultCurrency((float) ($totals?->total ?? 0)),
            'tax_total' => Money::inDefaultCurrency((float) ($totals?->tax_total ?? 0)),
            'discount_total' => Money::inDefaultCurrency((float) ($totals?->discount_total ?? 0)),
            'net_paid' => Money::inDefaultCurrency((float) ($totals?->net_paid ?? 0)),
            'credit_notes' => $creditNotes,
            'unpaid' => $unpaid,
            'partial' => $partial,
            'outstanding' => Money::inDefaultCurrency(max(0, (float) ($totals?->total ?? 0) - (float) ($totals?->net_paid ?? 0))),
            'cash' => $collections['cash'],
            'upi' => $collections['upi'],
            'refunds' => $collections['refunds'],
            'payment_breakdown' => $collections,
        ];
    }

    public function export(array $filters = [], array $sorts = []): StreamedResponse
    {
        $query = $this->query($filters)
            ->sortBy($sorts)
            ->with(['branch:id,name', 'seller:id,legal_name', 'buyer:id,legal_name']);

        return response()->streamDownload(function () use ($query) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'invoice_number',
                'branch',
                'seller',
                'buyer',
                'type',
                'status',
                'settlement_status',
                'purpose',
                'invoice_kind',
                'currency',
                'subtotal',
                'tax_total',
                'discount_total',
                'total',
                'net_paid',
                'issued_at',
            ]);

            $query->chunk(500, function ($invoices) use ($stream) {
                foreach ($invoices as $invoice) {
                    fputcsv($stream, [
                        $this->sanitizeCsvValue($invoice->invoice_number),
                        $this->sanitizeCsvValue($invoice->branch?->name),
                        $this->sanitizeCsvValue($invoice->seller?->legal_name),
                        $this->sanitizeCsvValue($invoice->buyer?->legal_name),
                        $invoice->type->value,
                        $invoice->status->value,
                        $this->settlementStatus($invoice),
                        $invoice->purpose->value,
                        $invoice->invoice_kind->value,
                        $invoice->currency,
                        $invoice->getRawOriginal('subtotal'),
                        $invoice->getRawOriginal('tax_total'),
                        $invoice->getRawOriginal('discount_total'),
                        $invoice->getRawOriginal('total'),
                        $invoice->getRawOriginal('net_paid'),
                        $invoice->issued_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($stream);
        }, 'invoices-' . now()->format('YmdHis') . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Sanitize CSV value to prevent formula injection.
     * Prefixes values starting with =, +, -, @ with a tab character.
     */
    private function sanitizeCsvValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $firstChar = substr($value, 0, 1);
        if (in_array($firstChar, ['=', '+', '-', '@'], true)) {
            return "\t" . $value;
        }

        return $value;
    }

    /** @inheritDoc */
    public function getModel(): Invoice
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Invoice::class;
    }

    /** @inheritDoc */
    public function show(int|string $id): Invoice
    {
        return Invoice::query()
            ->where(
                fn($query) => $query->where('id', $id)
                    ->orWhere('uuid', $id)
                    ->orWhere('invoice_number', $id)
            )
            ->with([
                "seller",
                "buyer",
                "discounts",
                "taxes",
                "allocations" => fn($query) => $query->with(["payment"]),
                "lines",
                "branch:id,name",
                "referenceInvoice:id,invoice_number,uuid"
            ])
            ->firstOrFail();
    }


    /** @inheritDoc */
    public function getStructureFilters(): array
    {
        $branchFilter = GlobalStructureFilters::branch();
        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                "key" => 'status',
                "label" => __('invoice::invoices.filters.status'),
                "type" => 'select',
                "options" => InvoiceStatus::toArrayTrans(),
            ],
            [
                "key" => 'settlement_status',
                "label" => __('invoice::invoices.filters.settlement_status'),
                "type" => 'select',
                "options" => collect(['paid', 'partial', 'unpaid', 'refunded'])->map(fn(string $status) => [
                    'id' => $status,
                    'name' => __("invoice::invoices.settlement_statuses.{$status}"),
                ])->all(),
            ],
            [
                "key" => 'type',
                "label" => __('invoice::invoices.filters.type'),
                "type" => 'select',
                "options" => InvoiceType::toArrayTrans(),
            ],
            [
                "key" => 'purpose',
                "label" => __('invoice::invoices.filters.purpose'),
                "type" => 'select',
                "options" => InvoicePurpose::toArrayTrans(),
            ],
            [
                "key" => 'invoice_kind',
                "label" => __('invoice::invoices.filters.invoice_kind'),
                "type" => 'select',
                "options" => InvoiceKind::toArrayTrans(),
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    private function query(array $filters = []): Builder
    {
        return $this->getModel()
            ->query()
            ->filters($filters);
    }

    private function settlementStatus(Invoice $invoice): string
    {
        $total = (float) $invoice->getRawOriginal('total');
        $netPaid = (float) $invoice->getRawOriginal('net_paid');
        $refunded = (float) $invoice->getRawOriginal('refunded_amount');

        if ($refunded > 0) {
            return 'refunded';
        }

        if ($netPaid >= $total && $total > 0) {
            return 'paid';
        }

        return $netPaid > 0 ? 'partial' : 'unpaid';
    }
}
