<?php

namespace Modules\Payment\Gateways;

use InvalidArgumentException;
use Modules\Payment\Gateways\Contracts\PaymentGatewayDriver;
use Modules\Payment\Gateways\Drivers\ManualTerminalDriver;
use Modules\Payment\Gateways\Drivers\PineLabsPlutusDriver;
use Modules\Payment\Gateways\Drivers\RazorpayDriver;

/**
 * Resolves card-present terminal drivers by key, from config('payment.gateways').
 * New providers (Razorpay POS, Mswipe, ...) plug in by adding a driver + a case
 * here — the rest of the payment flow stays unchanged.
 */
class PaymentGatewayManager
{
    /** @var array<string, PaymentGatewayDriver> */
    private array $resolved = [];

    /** @param array<string,mixed> $config config('payment.gateways') */
    public function __construct(private readonly array $config)
    {
    }

    public function driver(?string $key = null): PaymentGatewayDriver
    {
        $key = $key ?: ($this->config['default'] ?? 'manual');

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $driver = $this->make($key);

        if (!$driver->isConfigured()) {
            throw new InvalidArgumentException("Payment gateway [$key] is not configured.");
        }

        return $this->resolved[$key] = $driver;
    }

    /** Whether a gateway key is usable right now. */
    public function isConfigured(string $key): bool
    {
        try {
            return $this->make($key)->isConfigured();
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Keys of all configured/usable gateways (for the POS to offer terminal pay).
     *
     * @return list<string>
     */
    public function available(): array
    {
        return array_values(array_filter(
            ['manual', 'pinelabs', 'razorpay'],
            fn (string $key): bool => $this->isConfigured($key),
        ));
    }

    private function make(string $key): PaymentGatewayDriver
    {
        return match ($key) {
            'manual' => new ManualTerminalDriver(),
            'pinelabs' => new PineLabsPlutusDriver($this->config['pinelabs'] ?? []),
            'razorpay' => new RazorpayDriver($this->config['razorpay'] ?? []),
            default => throw new InvalidArgumentException("Unknown payment gateway [$key]."),
        };
    }
}
