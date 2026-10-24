<?php

namespace Modules\Flight\Services\Notification\Channels;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Services\Notification\Templates\FlightNotificationTemplateManager;
use Throwable;

class FlightEmailChannel implements FlightNotificationChannelInterface
{
    public function __construct(
        protected FlightNotificationTemplateManager $templateManager
    ) {}

    public function send(string $recipient, string $template, array $data, ?FlightBooking $booking = null): array
    {
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return [
                'success'            => false,
                'provider'           => 'email',
                'provider_reference' => null,
                'status'             => 'FAILED',
                'error'              => "Invalid email address: {$recipient}",
                'metadata'           => ['channel' => 'email', 'recipient' => $recipient],
            ];
        }

        $rendered = $this->templateManager->render($template, $data);
        $subject = $rendered['subject'];
        $html = $rendered['html'];
        $messageId = 'EMAIL-' . strtoupper(Str::random(12));

        try {
            Mail::html($html, function ($message) use ($recipient, $subject) {
                $message->to($recipient)->subject($subject);
            });

            return [
                'success'            => true,
                'provider'           => config('mail.default', 'smtp'),
                'provider_reference' => $messageId,
                'status'             => 'SENT',
                'error'              => null,
                'metadata'           => [
                    'channel'    => 'email',
                    'recipient'  => $recipient,
                    'subject'    => $subject,
                    'message_id' => $messageId,
                ],
            ];
        } catch (Throwable $e) {
            Log::warning("FlightEmailChannel: Failed to deliver email to {$recipient}", [
                'error'    => $e->getMessage(),
                'template' => $template,
            ]);

            return [
                'success'            => false,
                'provider'           => config('mail.default', 'smtp'),
                'provider_reference' => $messageId,
                'status'             => 'FAILED',
                'error'              => $e->getMessage(),
                'metadata'           => [
                    'channel'    => 'email',
                    'recipient'  => $recipient,
                    'subject'    => $subject,
                    'message_id' => $messageId,
                ],
            ];
        }
    }
}
