<?php

namespace Modules\Invoice\Services\DiscountBuilder;

use Illuminate\Support\Collection;
use Modules\Invoice\Models\Invoice;
use Modules\Invoice\Models\InvoiceDiscount;
use Modules\Order\Models\OrderDiscount;
use Modules\Voucher\Models\Voucher;

class DiscountBuilderService implements DiscountBuilderServiceInterface
{
    /** @inheritDoc */
    public function createDiscounts(Invoice $invoice, Collection $orders): Collection
    {
        $grouped = collect();
        $discounts = collect();

        foreach ($orders as $order) {
            $d = $order->discount;
            if (is_null($d)) {
                continue;
            }

            $discountType = $d->type instanceof \BackedEnum ? $d->type->value : (string) $d->type;
            $key = $discountType . '-' . $d->discountable_type . '-' . $d->discountable_id;

            if (!$grouped->has($key)) {
                $grouped[$key] = (object)[
                    'discount' => $d,
                    'amount' => 0,
                ];
            }

            $grouped[$key]->amount += $d->amount->amount();
        }

        foreach ($grouped as $item) {
            /** @var OrderDiscount $d */
            $d = $item->discount;

            $discounts->push(
                InvoiceDiscount::query()
                    ->create([
                        'invoice_id' => $invoice->id,
                        'discountable_type' => $d->discountable_type,
                        'discountable_id' => $d->discountable_id,
                        'source' => $d->type instanceof \BackedEnum ? $d->type->value : (string) $d->type,
                        'name' => $d->discountable_type == Voucher::class
                            ? $d->discountable->code
                            : $d->discountable?->getTranslations('name'),
                        'currency' => $d->currency,
                        'currency_rate' => $d->currency_rate,
                        'amount' => $item->amount,
                    ])
            );
        }

        return $discounts;
    }
}
