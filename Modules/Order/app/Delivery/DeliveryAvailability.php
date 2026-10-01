<?php

namespace Modules\Order\Delivery;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Modules\Branch\Models\Branch;

/** Tenant delivery ordering switch and weekly hours in the outlet's timezone. */
final class DeliveryAvailability
{
    private const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function status(Branch $branch, ?CarbonInterface $at = null, ?array $policy = null): array
    {
        $policy ??= [
            'delivery_ordering_enabled' => setting('delivery_ordering_enabled', true),
            'delivery_schedule_enabled' => setting('delivery_schedule_enabled', false),
            'delivery_hours' => setting('delivery_hours', []),
        ];
        if (! (bool) ($policy['delivery_ordering_enabled'] ?? true)) {
            return ['available' => false, 'code' => 'DELIVERY_DISABLED',
                'message' => 'Delivery ordering is currently disabled by this restaurant.'];
        }
        if (! (bool) ($policy['delivery_schedule_enabled'] ?? false)) {
            return ['available' => true, 'code' => null, 'message' => null];
        }

        $hours = $policy['delivery_hours'] ?? [];
        if (! is_array($hours)) return $this->closed();

        try {
            $now = $at ? CarbonImmutable::instance($at)->setTimezone($branch->timezone ?: config('app.timezone'))
                : CarbonImmutable::now($branch->timezone ?: config('app.timezone'));
        } catch (\Throwable) {
            return $this->closed();
        }

        $day = $now->dayOfWeek;
        $currentMinute = $now->hour * 60 + $now->minute;
        foreach ([$day, ($day + 6) % 7] as $index) {
            $slot = $hours[self::DAYS[$index]] ?? null;
            if (! is_array($slot) || array_diff(array_keys($slot), ['open', 'close']) !== []) continue;
            $open = $this->minute($slot['open'] ?? null);
            $close = $this->minute($slot['close'] ?? null);
            if ($open === null || $close === null || $open === $close) continue;
            $openNow = $index === $day
                ? ($open < $close ? $currentMinute >= $open && $currentMinute < $close : $currentMinute >= $open)
                : ($open > $close && $currentMinute < $close);
            if ($openNow) return ['available' => true, 'code' => null, 'message' => null];
        }

        return $this->closed();
    }

    public function assertAvailable(Branch $branch): void
    {
        $status = $this->status($branch);
        abort_unless($status['available'], 422, $status['message']);
    }

    private function minute(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $value)) return null;

        return (int) substr($value, 0, 2) * 60 + (int) substr($value, 3, 2);
    }

    private function closed(): array
    {
        return ['available' => false, 'code' => 'DELIVERY_OUTSIDE_HOURS',
            'message' => 'Delivery ordering is closed now. Please check this restaurant’s delivery days and hours.'];
    }
}
