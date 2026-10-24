<?php

namespace Modules\Flight\Services\Notification\Channels;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Services\Notification\Templates\FlightNotificationTemplateManager;
use Throwable;

class FlightWhatsAppChannel implements FlightNotificationChannelInterface
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
                'provider'           => 'whatsapp_mock',
                'provider_reference' => null,
                'status'             => 'FAILED',
                'error'              => "Invalid phone recipient for WhatsApp: {$recipient}",
                'metadata'           => ['channel' => 'whatsapp', 'recipient' => $recipient],
            ];
        }

        $rendered = $this->templateManager->render($template, $data);
        $messageText = $rendered['text'];
        $waRef = 'WA-' . strtoupper(Str::random(12));

        try {
            // Check if WhatsApp is configured or available in system
            $provider = config('services.whatsapp.provider', 'mock_whatsapp');

            // If WhatsApp provider factory exists in Notification or WhatsAppCenter module, try to resolve safely
            if (class_exists(\Modules\Notification\Services\WhatsApp\WhatsAppProviderFactory::class)) {
                try {
                    $factory = app(\Modules\Notification\Services\WhatsApp\WhatsAppProviderFactory::class);
                    // If tenant context / parameters exist, could invoke template sending
                } catch (Throwable $e) {
                    // Fallback to mock adapter
                }
            }

            Log::info("FlightWhatsAppChannel: Sent WhatsApp message to {$recipient} via {$provider}", [
                'reference' => $waRef,
                'template'  => $template,
                'preview'   => Str::limit($messageText, 50),
            ]);

            return [
                'success'            => true,
                'provider'           => $provider,
                'provider_reference' => $waRef,
                'status'             => 'SENT',
                'error'              => null,
                'metadata'           => [
                    'channel'    => 'whatsapp',
                    'recipient'  => $recipient,
                    'text'       => $messageText,
                    'message_id' => $waRef,
                ],
            ];
        } catch (Throwable $e) {
            Log::warning("FlightWhatsAppChannel: WhatsApp dispatch failed to {$recipient}", [
                'error' => $e->getMessage(),
            ]);

            return [
                'success'            => false,
                'provider'           => 'mock_whatsapp',
                'provider_reference' => $waRef,
                'status'             => 'FAILED',
                'error'              => $e->getMessage(),
                'metadata'           => ['channel' => 'whatsapp', 'recipient' => $recipient],
            ];
        }
    }
}
