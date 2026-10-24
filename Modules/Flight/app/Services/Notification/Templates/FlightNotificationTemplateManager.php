<?php

namespace Modules\Flight\Services\Notification\Templates;

use Illuminate\Support\Str;

class FlightNotificationTemplateManager
{
    /**
     * Keys strictly prohibited in rendered notifications.
     */
    protected const SENSITIVE_KEYS = [
        'jwt',
        'token',
        'api_key',
        'secret',
        'key',
        'password',
        'pin',
        'cvv',
        'supplier_credentials',
        'bearer',
        'auth_token',
        'client_secret',
        'webhook_secret',
    ];

    /**
     * Sanitize dynamic template data to prevent leakage of credentials or sensitive tokens.
     */
    public function sanitizeData(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            // Filter out any key containing sensitive patterns
            $isSensitive = false;
            foreach (self::SENSITIVE_KEYS as $sensitivePattern) {
                if (str_contains($normalizedKey, $sensitivePattern)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeData($value);
            } else {
                $sanitized[$key] = is_string($value) ? e($value) : $value;
            }
        }

        return $sanitized;
    }

    /**
     * Render subject, plain text message, and HTML message for given template.
     */
    public function render(string $template, array $rawPayload): array
    {
        $data = $this->sanitizeData($rawPayload);

        $bookingRef = $data['booking_reference'] ?? $data['booking_ref'] ?? 'N/A';
        $pnr = $data['pnr'] ?? 'PENDING';
        $paxName = $data['passenger_name'] ?? $data['contact_name'] ?? 'Valued Customer';
        $flightNumber = $data['flight_number'] ?? 'Flight';
        $origin = $data['origin'] ?? 'DEP';
        $destination = $data['destination'] ?? 'ARR';
        $departure = $data['departure'] ?? $data['departure_time'] ?? '';
        $amount = isset($data['amount']) ? number_format((float) $data['amount'], 2) : '0.00';
        $currency = $data['currency'] ?? 'INR';
        $ticketNumber = $data['ticket_number'] ?? 'N/A';
        $refundAmount = isset($data['refund_amount']) ? number_format((float) $data['refund_amount'], 2) : '0.00';
        $eticketRef = $data['eticket_reference'] ?? $data['e_ticket_number'] ?? 'N/A';
        $reason = $data['reason'] ?? $data['failure_reason'] ?? '';

        switch ($template) {
            case 'booking_confirmation':
                $subject = "Booking Confirmed: {$bookingRef} | {$flightNumber} ({$origin} → {$destination})";
                $text = "Dear {$paxName}, your booking {$bookingRef} for flight {$flightNumber} ({$origin} to {$destination}) has been confirmed. PNR: {$pnr}. Total: {$currency} {$amount}.";
                $headline = "Booking Confirmed!";
                $details = [
                    'Booking Reference' => $bookingRef,
                    'PNR'               => $pnr,
                    'Passenger'         => $paxName,
                    'Route'             => "{$origin} → {$destination}",
                    'Flight'            => $flightNumber,
                    'Total Paid'        => "{$currency} {$amount}",
                ];
                break;

            case 'payment_success':
                $subject = "Payment Successful for Booking {$bookingRef}";
                $text = "Dear {$paxName}, your payment of {$currency} {$amount} for booking {$bookingRef} was successful. We are issuing your tickets.";
                $headline = "Payment Received";
                $details = [
                    'Booking Reference' => $bookingRef,
                    'Amount Paid'       => "{$currency} {$amount}",
                    'Payment Status'    => 'SUCCESS',
                ];
                break;

            case 'payment_failure':
                $subject = "Payment Failed for Booking {$bookingRef}";
                $text = "Dear {$paxName}, your payment attempt of {$currency} {$amount} for booking {$bookingRef} failed. Reason: {$reason}. Please try again.";
                $headline = "Payment Failed";
                $details = [
                    'Booking Reference' => $bookingRef,
                    'Amount'            => "{$currency} {$amount}",
                    'Reason'            => $reason ?: 'Transaction declined by gateway.',
                ];
                break;

            case 'pnr_generated':
                $subject = "PNR Generated: {$pnr} for Booking {$bookingRef}";
                $text = "Dear {$paxName}, airline PNR {$pnr} has been generated for your flight {$flightNumber} ({$origin} to {$destination}). Booking Reference: {$bookingRef}.";
                $headline = "Airline PNR Assigned";
                $details = [
                    'Airline PNR'       => $pnr,
                    'Booking Reference' => $bookingRef,
                    'Flight'            => $flightNumber,
                    'Route'             => "{$origin} → {$destination}",
                ];
                break;

            case 'ticket_issued':
            case 'eticket_available':
                $subject = "E-Ticket Issued: {$bookingRef} | PNR {$pnr}";
                $text = "Dear {$paxName}, your e-ticket has been issued! PNR: {$pnr}, Ticket #: {$ticketNumber}, Download Ref: {$eticketRef}. Have a safe flight!";
                $headline = "E-Ticket Ready for Download";
                $details = [
                    'PNR'                 => $pnr,
                    'Ticket Number'       => $ticketNumber,
                    'E-Ticket Reference'  => $eticketRef,
                    'Booking Reference'   => $bookingRef,
                    'Flight'              => $flightNumber,
                    'Route'               => "{$origin} → {$destination}",
                ];
                break;

            case 'ticket_failed':
                $subject = "Ticketing Alert for Booking {$bookingRef}";
                $text = "Dear {$paxName}, we encountered an issue issuing your tickets for booking {$bookingRef}. Our operations team has been notified. Details: {$reason}.";
                $headline = "Ticketing Processing Delay";
                $details = [
                    'Booking Reference' => $bookingRef,
                    'Status'            => 'PENDING_OPERATIONS_REVIEW',
                    'Details'           => $reason,
                ];
                break;

            case 'hold_created':
                $subject = "Seats Held for Booking Reference {$bookingRef}";
                $text = "Dear {$paxName}, your flight seats on {$flightNumber} ({$origin} to {$destination}) are on hold. Please complete payment before expiry.";
                $headline = "Seats Reserved On Hold";
                $details = [
                    'Hold Reference' => $bookingRef,
                    'Flight'         => $flightNumber,
                    'Route'          => "{$origin} → {$destination}",
                ];
                break;

            case 'hold_expiry':
                $subject = "Flight Hold Expired: {$bookingRef}";
                $text = "Dear {$paxName}, your inventory hold for booking {$bookingRef} on flight {$flightNumber} has expired. The held seats have been released.";
                $headline = "Inventory Hold Expired";
                $details = [
                    'Hold Reference' => $bookingRef,
                    'Flight'         => $flightNumber,
                    'Status'         => 'EXPIRED',
                ];
                break;

            case 'cancellation_confirmation':
                $subject = "Booking Cancelled: {$bookingRef} | PNR {$pnr}";
                $text = "Dear {$paxName}, booking {$bookingRef} (PNR: {$pnr}) has been cancelled. Refund amount: {$currency} {$refundAmount}.";
                $headline = "Booking Cancellation Confirmed";
                $details = [
                    'Booking Reference' => $bookingRef,
                    'PNR'               => $pnr,
                    'Refundable Amount' => "{$currency} {$refundAmount}",
                    'Reason'            => $reason ?: 'Customer requested cancellation.',
                ];
                break;

            case 'refund_initiated':
            case 'refund_completed':
                $subject = "Refund {$template}: {$currency} {$refundAmount} for Booking {$bookingRef}";
                $text = "Dear {$paxName}, your refund of {$currency} {$refundAmount} for booking {$bookingRef} is {$template}. Reference: {$eticketRef}.";
                $headline = "Refund " . ucfirst(str_replace('_', ' ', $template));
                $details = [
                    'Booking Reference' => $bookingRef,
                    'Refund Amount'     => "{$currency} {$refundAmount}",
                    'Status'            => strtoupper(str_replace('_', ' ', $template)),
                ];
                break;

            case 'reschedule_confirmation':
                $subject = "Flight Rescheduled: {$bookingRef} | New PNR {$pnr}";
                $text = "Dear {$paxName}, your flight has been successfully rescheduled! New Flight: {$flightNumber} ({$origin} → {$destination}). New PNR: {$pnr}.";
                $headline = "Flight Reschedule Confirmed";
                $details = [
                    'Booking Reference' => $bookingRef,
                    'New PNR'           => $pnr,
                    'New Flight'        => $flightNumber,
                    'New Route'         => "{$origin} → {$destination}",
                ];
                break;

            case 'wallet_debited':
                $subject = "B2B Wallet Debited: {$currency} {$amount} for Ref {$bookingRef}";
                $text = "Dear Partner, your B2B wallet was debited by {$currency} {$amount} for booking {$bookingRef}.";
                $headline = "Wallet Debit Notification";
                $details = [
                    'Reference'   => $bookingRef,
                    'Amount'      => "{$currency} {$amount}",
                    'Action'      => 'DEBIT',
                ];
                break;

            case 'wallet_refunded':
                $subject = "B2B Wallet Refunded: {$currency} {$refundAmount} for Ref {$bookingRef}";
                $text = "Dear Partner, your B2B wallet was credited with a refund of {$currency} {$refundAmount} for booking {$bookingRef}.";
                $headline = "Wallet Refund Credit";
                $details = [
                    'Reference'   => $bookingRef,
                    'Amount'      => "{$currency} {$refundAmount}",
                    'Action'      => 'REFUND_CREDIT',
                ];
                break;

            default:
                $subject = "Flight Update: {$bookingRef}";
                $text = "Dear {$paxName}, flight update for booking {$bookingRef}. Status: " . ($data['status'] ?? 'Updated');
                $headline = "Flight Notification";
                $details = [
                    'Booking Reference' => $bookingRef,
                ];
                break;
        }

        $html = $this->renderHtmlLayout($subject, $headline, $text, $details);

        return [
            'subject' => $subject,
            'text'    => $text,
            'html'    => $html,
            'data'    => $data,
        ];
    }

    /**
     * Render clean, modern transactional HTML email layout.
     */
    protected function renderHtmlLayout(string $subject, string $headline, string $text, array $details): string
    {
        $rowsHtml = '';
        foreach ($details as $label => $val) {
            $rowsHtml .= "<tr><td style=\"padding:8px 0;color:#64748b;font-size:13px;font-weight:600;width:40%;\">{$label}</td><td style=\"padding:8px 0;color:#0f172a;font-size:14px;font-weight:700;\">{$val}</td></tr>";
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$subject}</title>
</head>
<body style="margin:0;padding:24px 12px;background:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1e293b;">
  <table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 4px 12px rgba(0,0,0,0.05);">
    <tr>
      <td style="background:linear-gradient(135deg, #0284c7 0%, #0369a1 100%);padding:28px 24px;text-align:left;">
        <span style="color:#e0f2fe;font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;">FLIGHT NOTIFICATION</span>
        <h1 style="color:#ffffff;font-size:22px;margin:8px 0 0;font-weight:800;">{$headline}</h1>
      </td>
    </tr>
    <tr>
      <td style="padding:28px 24px;">
        <p style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 20px;">{$text}</p>
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;border-radius:12px;padding:16px 20px;margin-bottom:24px;">
          {$rowsHtml}
        </table>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;font-size:12px;color:#1e40af;">
          <strong>Security Notice:</strong> Never share sensitive booking references or financial verification codes with unauthorized parties.
        </div>
      </td>
    </tr>
    <tr>
      <td style="background:#f8fafc;padding:16px 24px;border-top:1px solid #e2e8f0;text-align:center;font-size:12px;color:#94a3b8;">
        Automated Flight SaaS Notification. Please do not reply directly to this email.
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
