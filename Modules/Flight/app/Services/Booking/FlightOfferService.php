<?php

namespace Modules\Flight\Services\Booking;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Models\FlightCoupon;
use Modules\Flight\Models\FlightDiscount;
use Modules\Flight\Models\FlightFareQuote;
use Modules\Flight\Models\FlightOffer;

/**
 * Customer-side flight coupons (entered by code) and flight discounts (picked
 * from a list). Only the offers of the tenant that owns the quote are ever
 * considered.
 */
class FlightOfferService
{
    public function offersForQuote(string $quoteIdentifier): array
    {
        $quote = $this->quote($quoteIdentifier);
        $subtotal = (float) $quote->customer_price;

        $offers = $this->coupons($quote->tenant_id)->get()->filter(function (FlightCoupon $coupon) use ($subtotal, $quote) {
            if ($coupon->minimum_spend !== null && $subtotal < (float) $coupon->minimum_spend) return false;
            if ($coupon->maximum_spend !== null && $subtotal > (float) $coupon->maximum_spend) return false;
            if ($coupon->usageLimitReached()) return false;
            if (! $this->matchesFlightConditions($coupon, $quote)) return false;
            return true;
        })->map(fn (FlightCoupon $coupon) => [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'name' => $coupon->localized('name'),
            'description' => $coupon->localized('description'),
            'value_text' => $coupon->valueText(),
            'minimum_spend' => $coupon->minimum_spend,
            'ends_at' => optional($coupon->end_date)->toDateString(),
        ])->values();

        return [
            'offers' => $offers,
            'discounts' => $this->discountOptionsForQuote($quote),
        ];
    }

    public function validateCode(string $quoteIdentifier, string $code, ?int $discountId = null): array
    {
        $quote = $this->quote($quoteIdentifier);
        $coupon = $this->findCoupon($quote->tenant_id, $code);

        if (! $coupon || $coupon->usageLimitReached()) {
            throw new DomainException('This offer code is invalid, expired, or has reached its usage limit.');
        }
        if ($this->customerLimitReached($coupon, $quote->user_id)) {
            throw new DomainException('You have already used this offer the maximum number of times.');
        }

        $selectedDiscount = $discountId ? $this->validateDiscount($quote, $discountId) : null;
        $baseSubtotal = max(0, (float) $quote->customer_price);
        $subtotal = max(0, $baseSubtotal - (float) ($selectedDiscount['discount'] ?? 0));
        $minimum = (float) ($coupon->minimum_spend ?? 0);
        $maximum = $coupon->maximum_spend;
        if ($subtotal < $minimum || ($maximum !== null && $subtotal > (float) $maximum)) {
            throw new DomainException('This offer does not meet the booking amount requirements.');
        }
        if (! $this->matchesFlightConditions($coupon, $quote)) {
            throw new DomainException('This offer is not valid for the selected flight, route, or fare family.');
        }

        $discount = $coupon->discountOn($subtotal);

        if ($discount <= 0) throw new DomainException('This offer does not apply a discount to this booking.');

        return [
            'code' => $coupon->code,
            'name' => $coupon->localized('name'),
            'discount' => $discount,
            'automatic_discount' => (float) ($selectedDiscount['discount'] ?? 0),
            'subtotal' => round($subtotal, 2),
            'payable' => round($subtotal - $discount, 2),
        ];
    }

    public function discountOptionsForQuote(FlightFareQuote|string $quoteIdentifier): array
    {
        $quote = $quoteIdentifier instanceof FlightFareQuote ? $quoteIdentifier : $this->quote($quoteIdentifier);
        $subtotal = max(0, (float) $quote->customer_price);
        $options = [];

        foreach ($this->discounts($quote->tenant_id)->get() as $offer) {
            if (! $this->matchesFlightConditions($offer, $quote)) continue;

            $reason = null;
            if ($offer->minimum_spend !== null && $subtotal < (float) $offer->minimum_spend) {
                $reason = 'Minimum spend not met';
            } elseif ($offer->maximum_spend !== null && $subtotal > (float) $offer->maximum_spend) {
                $reason = 'Booking exceeds the maximum spend';
            } elseif ($offer->usageLimitReached()) {
                $reason = 'Discount usage limit reached';
            }
            if ($this->customerLimitReached($offer, $quote->user_id)) $reason = 'You have already used this discount';

            $discount = $offer->discountOn($subtotal);
            $options[] = [
                'id' => $offer->id,
                'name' => $offer->localized('name'),
                'description' => $offer->localized('description'),
                'value_text' => $offer->valueText(),
                'discount' => $discount,
                'eligible' => $reason === null && $discount > 0,
                'reason' => $reason ?? ($discount > 0 ? null : 'Discount does not apply to this fare'),
            ];
        }
        return $options;
    }

    public function validateDiscount(FlightFareQuote|string $quoteIdentifier, int $discountId): array
    {
        $quote = $quoteIdentifier instanceof FlightFareQuote ? $quoteIdentifier : $this->quote($quoteIdentifier);
        $offer = $this->discounts($quote->tenant_id)->whereKey($discountId)->first();
        if (! $offer) {
            throw new DomainException('This flight discount is unavailable.');
        }
        if (! $this->matchesFlightConditions($offer, $quote)) {
            throw new DomainException('This discount is not valid for the selected flight or fare family.');
        }

        $subtotal = max(0, (float) $quote->customer_price);
        if ($offer->minimum_spend !== null && $subtotal < (float) $offer->minimum_spend) {
            throw new DomainException('This discount requires a higher booking amount.');
        }
        if ($offer->maximum_spend !== null && $subtotal > (float) $offer->maximum_spend) {
            throw new DomainException('This discount is not available for this booking amount.');
        }
        if ($offer->usageLimitReached()) {
            throw new DomainException('This discount has reached its usage limit.');
        }
        if ($this->customerLimitReached($offer, $quote->user_id)) {
            throw new DomainException('You have already used this discount the maximum number of times.');
        }

        $discount = $offer->discountOn($subtotal);
        if ($discount <= 0) throw new DomainException('This discount does not apply to this booking.');

        return [
            'id' => $offer->id,
            'name' => $offer->localized('name'),
            'discount' => $discount,
            'subtotal' => round($subtotal, 2),
            'payable' => round($subtotal - $discount, 2),
        ];
    }

    public function redeemForBooking(FlightBooking $booking): void
    {
        $code = data_get($booking->meta, 'flight_offer.code');
        $automaticId = data_get($booking->meta, 'flight_automatic_discount.id');
        // Offers are looked up in the booking's own tenant, even if disabled since.
        $offers = collect();
        if ($code) {
            $offers->push(FlightCoupon::query()->forTenant($booking->tenant_id)
                ->whereRaw('LOWER(code) = ?', [mb_strtolower(trim((string) $code))])->first());
        }
        if ($automaticId) {
            $offers->push(FlightDiscount::query()->forTenant($booking->tenant_id)->find($automaticId));
        }

        foreach ($offers->filter() as $offer) {
            $inserted = DB::table('flight_offer_redemptions')->insertOrIgnore([
                'offer_type' => $offer->kind(),
                'offer_id' => $offer->id,
                'booking_id' => $booking->id,
                'user_id' => $booking->user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted) $offer->increment('used');
        }
    }

    public function quote(string $identifier): FlightFareQuote
    {
        $quote = FlightFareQuote::query()->where('quote_id', $identifier)
            ->orWhere('id', is_numeric($identifier) ? (int) $identifier : 0)->first();
        if (! $quote || $quote->isExpired() || $quote->status !== 'ACTIVE') {
            throw new DomainException('The flight fare quote is unavailable or expired. Search and select the flight again.');
        }
        return $quote;
    }

    protected function coupons(?int $tenantId): Builder
    {
        return FlightCoupon::query()->forTenant($tenantId)->availableNow();
    }

    protected function discounts(?int $tenantId): Builder
    {
        return FlightDiscount::query()->forTenant($tenantId)->availableNow();
    }

    protected function findCoupon(?int $tenantId, string $code): ?FlightCoupon
    {
        $code = mb_strtolower(trim($code));
        if ($code === '') return null;

        return $this->coupons($tenantId)->whereRaw('LOWER(code) = ?', [$code])->first();
    }

    protected function customerLimitReached(FlightOffer $offer, ?int $customerId): bool
    {
        if (! $customerId || ! $offer->per_customer_limit) return false;

        $redemptions = DB::table('flight_offer_redemptions')
            ->where('offer_type', $offer->kind())
            ->where('offer_id', $offer->id)
            ->where('user_id', $customerId)
            ->count();

        return $redemptions >= (int) $offer->per_customer_limit;
    }

    protected function matchesFlightConditions(FlightOffer $offer, FlightFareQuote $quote): bool
    {
        $conditions = (array) ($offer->conditions ?? []);
        $quote->loadMissing([
            'flightInventory.airline',
            'flightInventory.originAirport',
            'flightInventory.destinationAirport',
            'flightFare.fareFamily',
        ]);

        $itinerary = (array) ($quote->selected_itinerary ?? []);
        $inventory = $quote->flightInventory;
        $fare = $quote->flightFare;
        $airlineCode = data_get($itinerary, 'airline.code') ?: $inventory?->airline?->iata_code;
        $flightNumber = data_get($itinerary, 'flight_number') ?: $inventory?->flight_number;
        $origin = data_get($itinerary, 'origin.code') ?: $inventory?->originAirport?->iata_code;
        $destination = data_get($itinerary, 'destination.code') ?: $inventory?->destinationAirport?->iata_code;
        $fareFamily = data_get($quote->fare_details, 'fare_family');
        $fareFamilyCode = $fare?->fareFamily?->code ?: $fare?->fare_family_code;
        $fareFamilyName = $fare?->fareFamily?->name;

        return $this->matchesAny($conditions['airlines'] ?? [], [$airlineCode])
            && $this->matchesAny($conditions['flight_numbers'] ?? [], [$flightNumber])
            && $this->matchesAny($conditions['routes'] ?? [], [$origin && $destination ? "{$origin}-{$destination}" : null])
            && $this->matchesAny($conditions['fare_families'] ?? [], [
                data_get($fareFamily, 'code'),
                data_get($fareFamily, 'name'),
                is_string($fareFamily) ? $fareFamily : null,
                $fareFamilyCode,
                $fareFamilyName,
            ]);
    }

    protected function matchesAny(array|string|null $allowed, array $actual): bool
    {
        if (empty($allowed)) return true;
        $allowed = array_map(fn ($value) => $this->normalizeCondition($value), (array) $allowed);
        $actual = array_filter(array_map(fn ($value) => $this->normalizeCondition($value), $actual));
        return (bool) array_intersect($allowed, $actual);
    }

    protected function normalizeCondition(mixed $value): string
    {
        return mb_strtoupper(preg_replace('/\\s+/', '', trim((string) $value)) ?? '');
    }
}
