<?php

namespace Modules\Order\Models\Concerns;

use DB;
use Illuminate\Support\Collection;
use Modules\Cart\CartTax;
use Modules\Currency\Currency;
use Modules\Support\Money;
use Modules\Tax\Models\Tax;
use Modules\Tax\Services\TaxCalculationService;

/**
 * Tax calculation, sync and de-duplication for the Order model.
 */
trait HasOrderTaxes
{
    public function totalTax(): Money
    {
        $total = 0;

        if ($this->hasTax()) {
            ($this->relationLoaded("taxes") ? $this->taxes : $this->taxes()->get())
                ->each(function ($tax) use (&$total) {
                    $total += $tax->amount->amount();
                });
        }

        return isset($this->currency)
            ? new Money($total, $this->currency)
            : Money::inDefaultCurrency($total);
    }

    public function hasTax(): bool
    {
        return $this->taxes->isNotEmpty();
    }

    public function updateOrCreateTaxes(Collection $taxes): void
    {
        $taxIds = $taxes->map(fn(CartTax $tax) => $tax->id())->filter()->values();

        $this->taxes()
            ->whereNotIn("tax_id", $taxIds)
            ->delete();

        $existingTaxes = $this->taxes()
            ->whereIn('tax_id', $taxIds)
            ->get()
            ->keyBy('tax_id');

        foreach ($taxes as $tax) {
            $values = [
                'name' => $tax->translationsName(),
                'rate' => $tax->rate(),
                'currency' => $tax->currency(),
                'currency_rate' => $this->currency_rate,
                'amount' => $tax->amount()->amount(),
                'type' => $tax->type(),
                'compound' => $tax->compound(),
            ];

            $existingTax = $existingTaxes->get($tax->id());
            if ($existingTax) {
                $existingTax->update($values);
                continue;
            }

            $existingTaxes->put($tax->id(), $this->taxes()->create([
                'tax_id' => $tax->id(),
                ...$values,
            ]));
        }
    }

    public function updateOrCreateTax(CartTax $tax): void
    {
        $this->taxes()
            ->updateOrCreate(
                ['tax_id' => $tax->id()],
                [
                    'name' => $tax->translationsName(),
                    'rate' => $tax->rate(),
                    'currency' => $tax->currency(),
                    'currency_rate' => $this->currency_rate,
                    'amount' => $tax->amount()->amount(),
                    'type' => $tax->type(),
                    'compound' => $tax->compound(),
                ]
            );
    }

    /**
     * Sync active global taxes for the order's branch/type and recalculate totals.
     */
    public function syncApplicableOrderTaxes(): void
    {
        $applicableTaxRows = Tax::list($this->branch_id, true)
            ->filter(function (array $tax) {
                $orderTypes = $tax['order_types'] ?? [];

                return empty($orderTypes) || in_array($this->type->value, $orderTypes, true);
            })
            ->values();

        $applicableTaxIds = $applicableTaxRows->pluck('id');

        $this->taxes()
            ->whereNotIn('tax_id', $applicableTaxIds)
            ->delete();

        $taxModels = Tax::query()
            ->withOutGlobalBranchPermission()
            ->whereIn('id', $applicableTaxIds)
            ->get()
            ->keyBy('id');

        $existingTaxes = $this->taxes()
            ->whereIn('tax_id', $applicableTaxIds)
            ->get()
            ->keyBy('tax_id');

        foreach ($applicableTaxRows as $taxRow) {
            /** @var Tax|null $taxModel */
            $taxModel = $taxModels->get($taxRow['id']);

            $values = [
                'name' => $taxModel?->getTranslations('name') ?: ['en' => $taxRow['name']],
                'rate' => $taxRow['rate'],
                'currency' => $this->currency,
                'currency_rate' => $this->currency_rate,
                'amount' => 0,
                'type' => $taxRow['type'],
                'compound' => $taxRow['compound'],
            ];

            $existingTax = $existingTaxes->get($taxRow['id']);
            if ($existingTax) {
                $existingTax->update(collect($values)->except('amount')->all());
                continue;
            }

            $existingTaxes->put($taxRow['id'], $this->taxes()->create([
                'tax_id' => $taxRow['id'],
                ...$values,
            ]));
        }

        $this->deleteTaxesDuplicates();
        $this->unsetRelation('taxes');
        $this->loadMissing('taxes', 'discount');

        $precision = Currency::subunit($this->currency);
        $totalTaxes = $this->recalculateTaxes($this->subtotal->amount());
        $discountAmount = $this->discount?->amount->amount() ?? 0;

        $additionalAmount = max(0, (float) collect(data_get($this->fulfilmentDetails(), 'additional_payments', []))->sum());
        $this->update([
            'total' => round(($this->subtotal->amount() - $discountAmount) + $totalTaxes + $additionalAmount, $precision),
        ]);

        $this->refreshDueAmount();
    }

    /**
     * Delete duplicate taxes by tax_id, keeping the lowest id per tax.
     */
    public function deleteTaxesDuplicates(): int
    {
        return DB::table('order_taxes as t1')
            ->join(
                'order_taxes as t2',
                fn($join) => $join->on('t1.tax_id', '=', 't2.tax_id')
                    ->on('t1.id', '>', 't2.id')
                    ->on('t1.order_id', '=', 't2.order_id')
            )
            ->where('t1.order_id', $this->id)
            ->whereNull('t1.order_product_id')
            ->delete();
    }

    public function recalculateTaxes(float $basePrice): float
    {
        $calculatedTaxes = app(TaxCalculationService::class)
            ->calculate($basePrice, $this->taxes);

        foreach ($calculatedTaxes as $row) {
            $tax = $row['tax'];
            $taxAmount = (float) $row['amount'];

            if (abs($tax->amount->amount() - $taxAmount) > 0.0001) {
                $tax->update(["amount" => $taxAmount]);
            }
        }

        return $calculatedTaxes
            ->where('additive', true)
            ->sum('amount');
    }
}
