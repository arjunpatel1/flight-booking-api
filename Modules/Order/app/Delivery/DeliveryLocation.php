<?php

namespace Modules\Order\Delivery;

final readonly class DeliveryLocation
{
    public function __construct(public float $latitude, public float $longitude, public ?string $address = null) {}

    public static function fromAddress(array $address): ?self
    {
        if (! is_numeric($address['latitude'] ?? null) || ! is_numeric($address['longitude'] ?? null)) {
            return null;
        }

        $latitude = (float) $address['latitude'];
        $longitude = (float) $address['longitude'];
        if (! is_finite($latitude) || ! is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180) {
            return null;
        }

        return new self($latitude, $longitude, trim((string) ($address['address_line1'] ?? '')) ?: null);
    }

    public function distanceTo(self $other): float
    {
        $lat = deg2rad($other->latitude - $this->latitude);
        $lon = deg2rad($other->longitude - $this->longitude);
        $a = sin($lat / 2) ** 2 + cos(deg2rad($this->latitude)) * cos(deg2rad($other->latitude)) * sin($lon / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
    }
}
