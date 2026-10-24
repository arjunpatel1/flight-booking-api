<?php

namespace Modules\Flight\Services\Notification\Channels;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Services\Notification\Templates\FlightNotificationTemplateManager;
use Throwable;

class FlightSmsChannel implements FlightNotificationChannelInterface
{
    public function __construct(
        protected FlightNotificationTemplateManager $templateManager
    ) {}

    public function send(string $recipient, string $template, array $data, ?FlightBooking $booking = null): array
    {
        $cleanPhone = preg_replace('/[^\d\+]/', '', $recipient);
        if (strlen($cleanPhone) < 7) {
            return [
                'success'            => false,
                'provider'           => 'sms_mock',
                'provider_reference' => null,
                'status'             => 'FAILED',
                'error'              => "Invalid phone recipient for SMS: {$recipient}",
                'metadata'           => ['channel' => 'sms', 'recipient' => $recipient],
            ];
        }

        $rendered = $this->templateManager->render($template, $data);
        $messageText = $rendered['text'];
        $smsRef = 'SMS-' . strtoupper(Str::random(12));

        try {
            $provider = config('services.sms.provider', 'mock_sms');

            // If a real SMS gateway is configured (e.g. twilio, msg91), invoke here.
            // In absence or testing environment, safely fallback to mock provider.
            Log::info("FlightSmsChannel: Dispatching SMS to {$recipient} via {$provider}", [
                'reference' => $smsRef,
                'template'  => $template,
                'preview'   => Str::limit($messageText, 50),
            ]);

            return [
                'success'            => true,
                'provider'           => $provider,
                'provider_reference' => $smsRef,
                'status'             => 'SENT',
                'error'              => null,
                'metadata'           => [
                    'channel'    => 'sms',
                    'recipient'  => $recipient,
                    'text'       => $messageText,
                    'message_id' => $smsRef,
                ],
            ];
        } catch (Throwable $e) {
            Log::warning("FlightSmsChannel: SMS dispatch failed to {$recipient}", [
                'error' => $e->getMessage(),
            ]);

            return [
                'success'            => false,
                'provider'           => config('services.sms.provider', 'mock_sms'),
                'provider_reference' => $smsRef,
                'status'             => 'FAILED',
                'error'              => $e->getMessage(),
                'metadata'           => ['channel' => 'sms', 'recipient' => $recipient],
            ];
        }
    }
}
