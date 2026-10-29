<?php

namespace Modules\Flight\Services\Notification\Channels;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Services\Notification\Templates\FlightNotificationTemplateManager;
use Modules\Flight\Services\Ticketing\FlightEticketPdfService;
use Modules\Notification\Services\TenantMailerService;
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
        $fromName = $this->templateManager->brand()['name'];
        $ticket = $this->eticketAttachment($template, $booking);

        try {
            // The booking agency's own mail server when connected, else the platform mailer.
            $own = $booking?->tenant_id ? app(TenantMailerService::class)->resolve((int) $booking->tenant_id) : null;

            ($own['mailer'] ?? Mail::mailer())->html($html, function ($message) use ($recipient, $subject, $fromName, $ticket, $own) {
                $message->to($recipient)->subject($subject);
                // MAIL_FROM_NAME may be unset or a placeholder; show the brand.
                if ($own) {
                    $message->from($own['from_address'], $own['from_name'] ?: $fromName);
                } elseif (filled(config('mail.from.address'))) {
                    $message->from((string) config('mail.from.address'), $fromName);
                }
                if ($ticket) {
                    $message->attachData($ticket['data'], $ticket['name'], ['mime' => 'application/pdf']);
                }
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
                    'attachment' => $ticket['name'] ?? null,
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

    /**
     * The e-ticket PDF for ticket emails. A PDF failure is logged and the
     * email still goes out without the attachment.
     *
     * @return array{data: string, name: string}|null
     */
    protected function eticketAttachment(string $template, ?FlightBooking $booking): ?array
    {
        if (! $booking || ! in_array($template, ['ticket_issued', 'eticket_available'], true)) {
            return null;
        }

        try {
            $pdf = app(FlightEticketPdfService::class)->generate($booking);
            if (! str_starts_with($pdf, '%PDF-')) {
                return null;
            }

            $reference = preg_replace('/[^A-Za-z0-9-]/', '', (string) $booking->booking_reference) ?: 'booking';

            return ['data' => $pdf, 'name' => "E-Ticket-{$reference}.pdf"];
        } catch (Throwable $e) {
            Log::warning('FlightEmailChannel: e-ticket PDF could not be attached', [
                'booking' => $booking->booking_reference,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }
}
