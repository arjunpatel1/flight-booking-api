<?php

namespace Modules\Flight\Services\Booking;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Flight\Models\FlightFareQuote;
use Modules\Voucher\Models\Voucher;

class FlightOfferService
{
    public function offersForQuote(string $quoteIdentifier): array
    {
        $quote = $this->quote($quoteIdentifier);
        $subtotal = (float) $quote->customer_price;

        $offers = $this->flightVouchers($quote->tenant_id)->get()->filter(function (Voucher $voucher) use ($subtotal, $quote) {
            if ((bool) data_get($voucher->meta, 'flight_auto_apply', false)) return false;
            if ($voucher->minimum_spend && $subtotal < (float) $voucher->getRawOriginal('minimum_spend')) return false;
            if ($voucher->maximum_spend && $subtotal > (float) $voucher->getRawOriginal('maximum_spend')) return false;
            if ($voucher->usageLimitReached()) return false;
            if (! $this->matchesFlightConditions($voucher, $quote)) return false;
            return true;
        })->map(fn (Voucher $voucher) => [
            'id' => $voucher->id,
            'code' => $voucher->code,
            'name' => $voucher->name,
            'description' => $voucher->description,
            'value_text' => $voucher->getValueText(),
            'minimum_spend' => $voucher->getRawOriginal('minimum_spend'),
            'ends_at' => optional($voucher->end_date)->toDateString(),
        ])->values();

        return [
            'offers' => $offers,
            'discounts' => $this->discountOptionsForQuote($quote),
        ];
    }

    public function validateCode(string $quoteIdentifier, string $code, ?int $discountId = null): array
    {
        $quote = $this->quote($quoteIdentifier);
        $voucher = $this->flightVouchers($quote->tenant_id)
            ->whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))])->first();

        if (! $voucher || ! $voucher->valid() || $voucher->usageLimitReached()) {
            throw new DomainException('This offer code is invalid, expired, or has reached its usage limit.');
        }
        if ((bool) data_get($voucher->meta, 'flight_auto_apply', false)) {
            throw new DomainException('Select this in available flight discounts instead of entering it as a coupon code.');
        }
        $customerId = $quote->user_id;
        if ($customerId && $voucher->per_customer_limit) {
            $redemptions = DB::table('flight_offer_redemptions')->where('voucher_id', $voucher->id)->where('user_id', $customerId)->count();
            if ($redemptions >= (int) $voucher->per_customer_limit) {
                throw new DomainException('You have already used this offer the maximum number of times.');
            }
        }

        $selectedDiscount = $discountId ? $this->validateDiscount($quote, $discountId) : null;
        $baseSubtotal = max(0, (float) $quote->customer_price);
        $subtotal = max(0, $baseSubtotal - (float) ($selectedDiscount['discount'] ?? 0));
        $minimum = (float) ($voucher->getRawOriginal('minimum_spend') ?? 0);
        $maximum = $voucher->getRawOriginal('maximum_spend');
        if ($subtotal < $minimum || ($maximum !== null && $subtotal > (float) $maximum)) {
            throw new DomainException('This offer does not meet the booking amount requirements.');
        }
        if (! $this->matchesFlightConditions($voucher, $quote)) {
            throw new DomainException('This offer is not valid for the selected flight, route, or fare family.');
        }

        $discount = $this->calculateDiscount($voucher, $subtotal);

        if ($discount <= 0) throw new DomainException('This offer does not apply a discount to this booking.');

        return [
            'code' => $voucher->code,
            'name' => $voucher->name,
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
        $customerId = $quote->user_id;
        $options = [];

        foreach ($this->flightVouchers($quote->tenant_id)->get() as $voucher) {
            if (!(bool) data_get($voucher->meta, 'flight_auto_apply', false)) continue;
            if (! $this->matchesFlightConditions($voucher, $quote)) continue;

            $reason = null;
            if ($voucher->minimum_spend && $subtotal < (float) $voucher->getRawOriginal('minimum_spend')) {
                $reason = 'Minimum spend not met';
            } elseif ($voucher->maximum_spend && $subtotal > (float) $voucher->getRawOriginal('maximum_spend')) {
                $reason = 'Booking exceeds the maximum spend';
            } elseif ($voucher->usageLimitReached()) {
                $reason = 'Discount usage limit reached';
            }
            if ($customerId && $voucher->per_customer_limit) {
                $redemptions = DB::table('flight_offer_redemptions')->where('voucher_id', $voucher->id)->where('user_id', $customerId)->count();
                if ($redemptions >= (int) $voucher->per_customer_limit) $reason = 'You have already used this discount';
            }

            $discount = $this->calculateDiscount($voucher, $subtotal);
            $options[] = [
                'id' => $voucher->id,
                'name' => $voucher->name,
                'description' => $voucher->description,
                'value_text' => $voucher->getValueText(),
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
        $voucher = $this->flightVouchers($quote->tenant_id)->whereKey($discountId)->first();
        if (! $voucher || !(bool) data_get($voucher->meta, 'flight_auto_apply', false)) {
            throw new DomainException('This flight discount is unavailable.');
        }
        if (! $this->matchesFlightConditions($voucher, $quote)) {
            throw new DomainException('This discount is not valid for the selected flight or fare family.');
        }

        $subtotal = max(0, (float) $quote->customer_price);
        if ($voucher->minimum_spend && $subtotal < (float) $voucher->getRawOriginal('minimum_spend')) {
            throw new DomainException('This discount requires a higher booking amount.');
        }
        if ($voucher->maximum_spend && $subtotal > (float) $voucher->getRawOriginal('maximum_spend')) {
            throw new DomainException('This discount is not available for this booking amount.');
        }
        if ($voucher->usageLimitReached()) {
            throw new DomainException('This discount has reached its usage limit.');
        }
        if ($quote->user_id && $voucher->per_customer_limit) {
            $redemptions = DB::table('flight_offer_redemptions')->where('voucher_id', $voucher->id)->where('user_id', $quote->user_id)->count();
            if ($redemptions >= (int) $voucher->per_customer_limit) {
                throw new DomainException('You have already used this discount the maximum number of times.');
            }
        }

        $discount = $this->calculateDiscount($voucher, $subtotal);
        if ($discount <= 0) throw new DomainException('This discount does not apply to this booking.');

        return [
            'id' => $voucher->id,
            'name' => $voucher->name,
            'discount' => $discount,
            'subtotal' => round($subtotal, 2),
            'payable' => round($subtotal - $discount, 2),
        ];
    }

    public function redeemForBooking(\Modules\Flight\Models\FlightBooking $booking): void
    {
        $code = data_get($booking->meta, 'flight_offer.code');
        $automaticId = data_get($booking->meta, 'flight_automatic_discount.id');
        $vouchers = collect();
        if ($code) $vouchers->push(Voucher::query()->where('code', $code)->first());
        if ($automaticId) $vouchers->push(Voucher::query()->find($automaticId));

        foreach ($vouchers->filter() as $voucher) {
            $inserted = DB::table('flight_offer_redemptions')->insertOrIgnore([
                'voucher_id' => $voucher->id,
                'booking_id' => $booking->id,
                'user_id' => $booking->user_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted) $voucher->increment('used');
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

    protected function flightVouchers(?int $tenantId)
    {
        return Voucher::query()->where('is_active', true)
            ->whereJsonContains('conditions->channels', 'flights')
            ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', today()))
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->where(fn ($query) => $query->whereNull('meta->tenant_id')->orWhere('meta->tenant_id', $tenantId));
    }

    protected function calculateDiscount(Voucher $voucher, float $subtotal): float
    {
        $rawValue = (float) $voucher->getRawOriginal('value');
        $discount = $voucher->type->value === 'percent' ? $subtotal * $rawValue / 100 : $rawValue;
        if ($voucher->type->value === 'percent' && $voucher->getRawOriginal('max_discount')) {
            $discount = min($discount, (float) $voucher->getRawOriginal('max_discount'));
        }
        return round(min($subtotal, max(0, $discount)), 2);
    }

    protected function matchesFlightConditions(Voucher $voucher, FlightFareQuote $quote): bool
    {
        $conditions = (array) ($voucher->conditions ?? []);
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
