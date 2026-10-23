<?php

namespace Modules\Flight\Services\Notification\Channels;

use Modules\Flight\Models\FlightBooking;

interface FlightNotificationChannelInterface
{
    /**
     * Send notification through channel.
     * Must never throw fatal exceptions that break calling business transactions.
     *
     * @param string $recipient Email address, phone number, etc.
     * @param string $template Template name
     * @param array $data Dynamic sanitized parameters
     * @param FlightBooking|null $booking Associated booking if present
     * @return array Result array with keys: success (bool), provider (string), provider_reference (?string), status (string), error (?string), metadata (?array)
     */
    public function send(string $recipient, string $template, array $data, ?FlightBooking $booking = null): array;
}
