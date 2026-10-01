<?php

namespace Modules\Setting\Services\Delivery;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class DeliveryGeocoder
{
    public function search(string $query): array
    {
        $query = trim($query);

        return Cache::remember('delivery-geocode:'.sha1(mb_strtolower($query)), now()->addHours(12), function () use ($query): array {
            return $this->provider() === 'google' ? $this->googleSearch($query) : $this->openStreetMapSearch($query);
        });
    }

    public function reverse(float $latitude, float $longitude): array
    {
        $cacheKey = sprintf('delivery-reverse:%s:%.6f:%.6f', $this->provider(), $latitude, $longitude);

        return Cache::remember($cacheKey, now()->addHours(12), function () use ($latitude, $longitude): array {
            return $this->provider() === 'google'
                ? $this->googleReverse($latitude, $longitude)
                : $this->openStreetMapReverse($latitude, $longitude);
        });
    }

    private function provider(): string
    {
        return (string) setting('delivery_map_provider', 'openstreetmap');
    }

    private function openStreetMapSearch(string $query): array
    {
        $response = $this->openStreetMapClient()->get('https://nominatim.openstreetmap.org/search', [
            'q' => $query, 'format' => 'jsonv2', 'limit' => 5, 'addressdetails' => 1,
        ]);
        abort_unless($response->successful(), 502, 'Location search is temporarily unavailable.');

        return collect($response->json())->map(fn (array $row) => $this->normalizeOpenStreetMap($row))
            ->filter(fn (array $row) => $this->valid($row))->values()->all();
    }

    private function openStreetMapReverse(float $latitude, float $longitude): array
    {
        $response = $this->openStreetMapClient()->get('https://nominatim.openstreetmap.org/reverse', [
            'lat' => $latitude, 'lon' => $longitude, 'format' => 'jsonv2', 'addressdetails' => 1, 'zoom' => 18,
        ]);
        abort_unless($response->successful(), 502, 'Location details are temporarily unavailable.');
        $row = $this->normalizeOpenStreetMap((array) $response->json());

        return $this->valid($row) ? [$row] : [];
    }

    private function openStreetMapClient()
    {
        return Http::acceptJson()->timeout(8)->retry(2, 250)->withHeaders([
            'User-Agent' => 'NexDine/1.0 ('.config('app.url').')',
        ]);
    }

    private function normalizeOpenStreetMap(array $row): array
    {
        $address = (array) ($row['address'] ?? []);
        $road = $address['road'] ?? $address['pedestrian'] ?? $address['residential'] ?? $address['footway'] ?? $address['path'] ?? null;
        $house = $address['house_number'] ?? null;
        $place = $address['building'] ?? $address['amenity'] ?? $address['shop'] ?? $address['office'] ?? $address['house_name'] ?? null;
        $area = $address['suburb'] ?? $address['neighbourhood'] ?? $address['quarter'] ?? $address['city_district']
            ?? $address['locality'] ?? $address['hamlet'] ?? $address['village'] ?? null;
        $city = $address['city'] ?? $address['town'] ?? $address['municipality'] ?? $address['village'] ?? $address['county'] ?? null;
        $label = trim((string) ($row['display_name'] ?? ''));
        $addressLine = trim(collect([$house, $road ?: $place])->filter()->join(' '));

        return [
            'label' => $label,
            'latitude' => (string) ($row['lat'] ?? ''),
            'longitude' => (string) ($row['lon'] ?? ''),
            'address_line1' => $addressLine,
            'house_number' => $house,
            'place' => $place,
            'road' => $road,
            'area' => $area,
            'suburb' => $address['suburb'] ?? null,
            'neighbourhood' => $address['neighbourhood'] ?? null,
            'city' => $city,
            'state' => $address['state'] ?? $address['state_district'] ?? null,
            'postal_code' => $address['postcode'] ?? null,
            'country' => $address['country'] ?? null,
        ];
    }

    private function googleSearch(string $query): array
    {
        return $this->google(['address' => $query]);
    }

    private function googleReverse(float $latitude, float $longitude): array
    {
        return $this->google(['latlng' => $latitude.','.$longitude]);
    }

    private function google(array $parameters): array
    {
        $key = (string) setting('delivery_map_api_key');
        if ($key === '') {
            throw ValidationException::withMessages(['q' => 'Configure the Google Maps API key in Delivery settings first.']);
        }
        $response = Http::acceptJson()->timeout(8)->get(
            'https://maps.googleapis.com/maps/api/geocode/json',
            [...$parameters, 'key' => $key],
        );
        abort_unless($response->successful() && $response->json('status') === 'OK', 502, 'Google location search could not complete.');

        return collect($response->json('results', []))->take(5)->map(function (array $row): array {
            $components = [];
            foreach ((array) ($row['address_components'] ?? []) as $component) {
                foreach ((array) ($component['types'] ?? []) as $type) {
                    $components[$type] ??= $component['long_name'] ?? null;
                }
            }
            $road = $components['route'] ?? null;
            $house = $components['street_number'] ?? null;

            return [
                'label' => (string) ($row['formatted_address'] ?? ''),
                'latitude' => (string) data_get($row, 'geometry.location.lat'),
                'longitude' => (string) data_get($row, 'geometry.location.lng'),
                'address_line1' => trim(collect([$house, $road])->filter()->join(' ')),
                'house_number' => $house,
                'place' => $components['premise'] ?? $components['establishment'] ?? null,
                'road' => $road,
                'area' => $components['sublocality_level_1'] ?? $components['neighborhood'] ?? null,
                'suburb' => $components['sublocality_level_1'] ?? null,
                'neighbourhood' => $components['neighborhood'] ?? null,
                'city' => $components['locality'] ?? $components['administrative_area_level_2'] ?? null,
                'state' => $components['administrative_area_level_1'] ?? null,
                'postal_code' => $components['postal_code'] ?? null,
                'country' => $components['country'] ?? null,
            ];
        })->filter(fn (array $row) => $this->valid($row))->values()->all();
    }

    private function valid(array $row): bool
    {
        return $row['label'] !== '' && is_numeric($row['latitude']) && is_numeric($row['longitude']);
    }
}
