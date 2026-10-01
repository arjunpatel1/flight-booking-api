<?php

namespace Modules\Order\Support;

/** Compatibility for the labelled suffix previously appended by public checkout. */
final class OnlineOrderDetails
{
    public static function fromCheckout(array $data): array
    {
        return [
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            'fulfilment' => array_filter([
                'delivery_address' => ($data['type'] ?? null) === 'delivery' ? ($data['delivery_address'] ?? null) : null,
                'room_number' => $data['room_number'] ?? null,
                'payment_preference' => $data['payment_method'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
        ];
    }

    public static function legacy(?string $notes): array
    {
        $lines = preg_split('/\R/', $notes ?? '');
        $labels = [
            'DELIVERY ADDRESS' => 'delivery_summary',
            'PAYMENT PREFERENCE' => 'payment_preference',
            'Room / Table' => 'room_number',
            'Room Number' => 'room_number',
            'Customer' => 'customer_name',
            'Customer Mobile' => 'customer_mobile',
            'Customer mobile' => 'customer_mobile',
            'Mobile' => 'customer_mobile',
        ];
        $fulfilment = [];
        while ($lines) {
            $line = end($lines);
            if (!preg_match('/^([^:]+):\s*(.*)$/u', $line, $match) || !isset($labels[$match[1]])) break;
            $fulfilment[$labels[$match[1]]] = $match[2];
            array_pop($lines);
        }
        // Ordinary staff notes must remain untouched unless the public-checkout
        // suffix is identifiable. Never guess an address from arbitrary prose.
        if (!isset($fulfilment['payment_preference']) && !isset($fulfilment['delivery_summary'])) {
            return ['notes' => $notes, 'fulfilment' => []];
        }
        return ['notes' => trim(implode("\n", $lines)) ?: null, 'fulfilment' => $fulfilment];
    }
}
