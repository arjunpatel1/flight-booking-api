<?php

namespace Modules\Support;

/**
 * Reusable validation fragments built from `config/validation.php`.
 *
 * The point is consistency, not cleverness: every money field in the platform
 * should share one ceiling and one precision, so a limit can be tuned in one
 * place instead of being rediscovered in forty form requests.
 *
 * Each helper returns an array of rule tokens meant to be spread into an
 * existing rule set — it never replaces `required`/`nullable`, because whether
 * a field is optional is business logic and stays with the form request.
 *
 *   'amount' => ['required', ...InputLimit::money()],
 *   'qty'    => ['nullable', ...InputLimit::quantity(min: 1)],
 */
final class InputLimit
{
    /**
     * Currency amount: numeric, non-negative, bounded, fixed precision.
     */
    public static function money(float $min = 0, ?float $max = null): array
    {
        return [
            'numeric',
            "min:{$min}",
            'max:' . ($max ?? config('validation.money.max', 10_000_000)),
            'decimal:0,' . config('validation.money.decimals', 3),
        ];
    }

    /**
     * Line-item quantity. Fractional by default because weighed goods are real;
     * pass `integer: true` where only whole units make sense.
     */
    public static function quantity(float $min = 0, bool $integer = false): array
    {
        $max = config('validation.quantity.max', 10_000);

        return $integer
            ? ['integer', "min:{$min}", "max:{$max}"]
            : ['numeric', "min:{$min}", "max:{$max}", 'decimal:0,' . config('validation.quantity.decimals', 3)];
    }

    /**
     * A percentage. Always 0–100 — a 150% discount is never valid input.
     */
    public static function percent(): array
    {
        return [
            'numeric',
            'min:' . config('validation.percent.min', 0),
            'max:' . config('validation.percent.max', 100),
        ];
    }

    /**
     * Bounded free text. `$kind` selects a ceiling from config/validation.php
     * so "a note" means the same length everywhere.
     */
    public static function text(string $kind = 'name'): array
    {
        $limits = (array) config('validation.text', []);

        return ['string', 'max:' . ($limits[$kind] ?? 255)];
    }

    /**
     * A bounded list of ids for a bulk action. Bounds payload size and, more
     * usefully, the blast radius of a mis-clicked bulk operation.
     */
    public static function bulkIds(): array
    {
        return ['array', 'min:1', 'max:' . config('validation.counts.bulk_ids', 100)];
    }

    public static function lineItems(): array
    {
        return ['array', 'min:1', 'max:' . config('validation.counts.line_items', 200)];
    }
}
