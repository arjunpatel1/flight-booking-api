<?php

namespace Modules\Cart\Models;

use Modules\Support\Casts\AsSerialize;
use Modules\Support\Eloquent\Model;

class Cart extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'id',
        'data'
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            "data" => AsSerialize::class,
        ];
    }

    /**
     * Update cart totals
     *
     * @param array $cartData
     * @return array
     */
    public static function updateTotals(array $cartData): array
    {
        $subtotal = 0;
        $taxTotal = 0;
        $discountTotal = 0;
        $grandTotal = 0;

        if (isset($cartData['items']) && is_array($cartData['items'])) {
            foreach ($cartData['items'] as $item) {
                $subtotal += ($item['price'] * $item['quantity']);
                
                if (isset($item['tax']) && is_array($item['tax'])) {
                    foreach ($item['tax'] as $tax) {
                        $taxTotal += $tax['amount'];
                    }
                }
                
                if (isset($item['discount']) && is_array($item['discount'])) {
                    foreach ($item['discount'] as $discount) {
                        $discountTotal += $discount['amount'];
                    }
                }
            }
        }

        $grandTotal = $subtotal + $taxTotal - $discountTotal;

        return array_merge($cartData, [
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'discount_total' => $discountTotal,
            'grand_total' => $grandTotal,
        ]);
    }
}
