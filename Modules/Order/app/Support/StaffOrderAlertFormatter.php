<?php

namespace Modules\Order\Support;

use Carbon\CarbonInterface;

final class StaffOrderAlertFormatter
{
    public function format(
        string $sourceLabel,
        iterable $items,
        ?CarbonInterface $scheduledAt,
        ?string $instructions,
        string $timezone,
    ): string {
        return str($sourceLabel)->squish()->limit(80)->toString()
            .' • Items: '.$this->items($items);
    }

    public function items(iterable $items): string
    {
        $lines = collect($items)
            ->filter(fn (array $item) => (float) ($item['quantity'] ?? 0) > 0)
            ->map(function (array $item): string {
                $name = str((string) ($item['name'] ?? 'Item'))->squish()->limit(70);
                $quantity = rtrim(rtrim(number_format((float) $item['quantity'], 3, '.', ''), '0'), '.');

                return $name.' × '.($quantity !== '' ? $quantity : '1');
            });
        $visible = $lines->take(8);
        if ($lines->count() > 8) {
            $visible->push('+'.($lines->count() - 8).' more items');
        }

        return str($visible->isNotEmpty() ? $visible->implode(', ') : 'See order')->limit(700)->toString();
    }
}
