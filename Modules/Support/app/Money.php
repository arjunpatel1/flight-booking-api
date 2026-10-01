<?php

namespace Modules\Support;

use InvalidArgumentException;
use JsonSerializable;
use Modules\Currency\Currency;
use Modules\Currency\Models\CurrencyRate;
use NumberFormatter;
use RoundingMode;

readonly class Money implements JsonSerializable
{
    public function __construct(private int|float $amount, private string $currency) {}

    public static function inDefaultCurrency($amount): static
    {
        return new self($amount, self::defaultCurrency());
    }

    public function amount(): int|float
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function isZero(): bool
    {
        return $this->amount == 0;
    }

    public function add(Money $addend): Money
    {
        return $this->newInstance($this->amount + $addend->amount);
    }

    private function newInstance(int|float $amount): Money
    {
        return new self($amount, $this->currency);
    }

    public function subtract(Money $subtrahend): Money
    {
        return $this->newInstance($this->amount - $subtrahend->amount);
    }

    public function multiply(int|float $multiplier): Money
    {
        return $this->newInstance($this->amount * $multiplier);
    }

    public function divide(int|float $divisor): Money
    {
        return $this->newInstance($this->amount / $divisor);
    }

    public function lessThan(Money $other): bool
    {
        return $this->amount < $other->amount;
    }

    public function lessThanOrEqual(Money $other): bool
    {
        return $this->amount <= $other->amount;
    }

    public function greaterThan(Money $other): bool
    {
        return $this->amount > $other->amount;
    }

    public function greaterThanOrEqual(Money $other): bool
    {
        return $this->amount >= $other->amount;
    }

    public function round(?int $precision = null, int|RoundingMode|null $mode = null): Money
    {
        if ($precision === null) {
            $precision = Currency::subunit($this->currency);
        }
        // PHP 8.3-safe default rounding mode
        $mode = $mode ?? PHP_ROUND_HALF_UP;
        $amount = round($this->amount, $precision, $mode);

        return $this->newInstance($amount);
    }

    public function subunit(): int|float
    {
        $fraction = 10 ** Currency::subunit($this->currency);

        return (int) round($this->amount * $fraction);
    }

    public function ceil(): Money
    {
        return $this->newInstance(ceil($this->amount));
    }

    public function floor(): Money
    {
        return $this->newInstance(floor($this->amount));
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'formatted' => $this->format(),
            'currency' => $this->currency,
            'precision' => Currency::subunit($this->currency),
        ];
    }

    public function format(?string $currency = null, ?string $locale = null): string
    {
        $currency = $currency ?: $this->currency;
        $locale = $locale ?? setting('default_locale');

        $numberFormatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);

        $numberFormatter->setAttribute(NumberFormatter::FRACTION_DIGITS, Currency::subunit($currency));

        return $numberFormatter->formatCurrency($this->amount, $currency);
    }

    public function withConvertedDefaultCurrency($currencyRate = null): array
    {
        return [
            'original' => $this,
            'converted' => $this->convertToDefault($currencyRate),
        ];
    }

    public function convertToDefault($currencyRate = null): Money
    {
        $currency = self::defaultCurrency();
        $currencyRate = $currencyRate ?: CurrencyRate::for($currency);

        return new self($this->amount / $currencyRate, $currency);
    }

    public function convert($currency = null, $currencyRate = null): Money
    {
        $currency = $currency ?: self::defaultCurrency();
        $currencyRate = $currencyRate ?: CurrencyRate::for($currency);

        if (is_null($currencyRate)) {
            throw new InvalidArgumentException("Cannot convert the money to currency [$currency].");
        }

        return new self($this->amount * $currencyRate, $currency);
    }

    public function __toString(): string
    {
        return (string) $this->amount;
    }

    private static function defaultCurrency(): string
    {
        return setting('default_currency') ?: config('app.currency', 'INR');
    }
}
