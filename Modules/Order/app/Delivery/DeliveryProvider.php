<?php

namespace Modules\Order\Delivery;

interface DeliveryProvider
{
    public function code(): string;

    public function isConfigured(): bool;

    /** @return list<DeliveryQuote> */
    public function quotes(DeliveryLocation $pickup, DeliveryLocation $dropoff, string $orderReference, bool $cod): array;

    public function book(DeliveryQuote $quote, string $orderReference, string $idempotencyKey): DeliveryBookingResult;
}
