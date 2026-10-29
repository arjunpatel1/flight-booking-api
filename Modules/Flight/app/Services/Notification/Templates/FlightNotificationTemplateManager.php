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
            // Sent when the booking is created, before payment.
            case 'booking_confirmation':
                $subject = "Booking Received: {$bookingRef} | {$flightNumber} ({$origin} → {$destination})";
                $text = "Dear {$paxName}, thank you for booking with us. We have received your booking {$bookingRef} for flight {$flightNumber} ({$origin} to {$destination}). Please complete the payment to confirm your seats.";
                $headline = "Booking Received";
                $details = [
                    'Booking Reference' => $bookingRef,
                    'Passenger'         => $paxName,
                    'Flight'            => $flightNumber,
                    'Route'             => "{$origin} → {$destination}",
                    'Departure'         => $departure ?: 'As per itinerary',
                    'Amount Due'        => "{$currency} {$amount}",
                ];
                break;

            // Sent once payment succeeds: this is the customer's confirmation.
            case 'payment_success':
                $subject = "Booking Confirmed: {$bookingRef} | {$flightNumber} ({$origin} → {$destination})";
                $text = "Dear {$paxName}, your payment of {$currency} {$amount} was successful and your booking {$bookingRef} is confirmed. Your e-ticket will be emailed to you as soon as it is issued.";
                $headline = "Booking Confirmed";
                $details = [
                    'Booking Reference' => $bookingRef,
                    'PNR'               => $pnr,
                    'Passenger'         => $paxName,
                    'Flight'            => $flightNumber,
                    'Route'             => "{$origin} → {$destination}",
                    'Departure'         => $departure ?: 'As per itinerary',
                    'Amount Paid'       => "{$currency} {$amount}",
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
                $subject = "Your E-Ticket: {$bookingRef} | PNR {$pnr} | {$flightNumber} ({$origin} → {$destination})";
                $text = "Dear {$paxName}, your e-ticket has been issued. Your e-ticket is attached to this email as a PDF; please carry it (printed or on your phone) along with a valid photo ID. Have a safe flight!";
                $headline = "Your E-Ticket is Ready";
                $details = [
                    'PNR'                 => $pnr,
                    'Ticket Number'       => $ticketNumber,
                    'E-Ticket Reference'  => $eticketRef,
                    'Booking Reference'   => $bookingRef,
                    'Flight'              => $flightNumber,
                    'Route'               => "{$origin} → {$destination}",
                    'Departure'           => $departure ?: 'As per itinerary',
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
        $brand = $this->brand();
        $color = $brand['color'];
        $name = e($brand['name']);

        $rowsHtml = '';
        foreach ($details as $label => $val) {
            $rowsHtml .= "<tr><td style=\"padding:9px 0;color:#64748b;font-size:13px;font-weight:600;width:42%;border-bottom:1px solid #eef2f7;\">{$label}</td><td style=\"padding:9px 0;color:#0f172a;font-size:14px;font-weight:700;border-bottom:1px solid #eef2f7;\">{$val}</td></tr>";
        }

        $logo = $brand['logo_url'] !== ''
            ? '<img src="' . e($brand['logo_url']) . '" width="52" height="52" alt="' . $name . '" style="display:block;width:52px;height:52px;border-radius:50%;object-fit:cover;background:#ffffff;">'
            : '<div style="width:52px;height:52px;line-height:52px;text-align:center;border-radius:50%;background:' . $color . ';color:#ffffff;font-size:22px;font-weight:800;">' . e(mb_strtoupper(mb_substr($brand['name'], 0, 1))) . '</div>';

        $button = $brand['bookings_url'] !== ''
            ? '<p style="margin:0 0 24px;text-align:center;"><a href="' . e($brand['bookings_url']) . '" style="display:inline-block;padding:13px 26px;background:' . $color . ';color:#ffffff;text-decoration:none;border-radius:10px;font-weight:700;font-size:15px;">View My Booking</a></p>'
            : '';

        $support = array_filter([
            $brand['support_phone'] !== '' ? 'Call ' . e($brand['support_phone']) : null,
            $brand['support_email'] !== '' ? 'email <a href="mailto:' . e($brand['support_email']) . '" style="color:' . $color . ';">' . e($brand['support_email']) . '</a>' : null,
        ]);
        $supportLine = $support ? '<p style="margin:0 0 16px;font-size:13px;color:#475569;text-align:center;">Need help? ' . implode(' or ', $support) . '.</p>' : '';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <title>{$subject}</title>
</head>
<body style="margin:0;padding:24px 12px;background:#f4f6f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#1e293b;">
  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width:620px;margin:0 auto;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 10px 30px rgba(15,23,42,0.06);">
    <tr><td style="height:5px;background:{$color};"></td></tr>
    <tr>
      <td style="padding:22px 26px;border-bottom:1px solid #eef2f7;">
        <table role="presentation" cellpadding="0" cellspacing="0"><tr>
          <td>{$logo}</td>
          <td style="padding-left:14px;">
            <div style="font-size:19px;font-weight:800;color:#0f172a;">{$name}</div>
            <div style="margin-top:3px;font-size:12px;color:#64748b;">Flight Booking</div>
          </td>
        </tr></table>
      </td>
    </tr>
    <tr>
      <td style="padding:28px 26px 8px;">
        <h1 style="margin:0 0 14px;font-size:23px;line-height:1.3;color:#0f172a;font-weight:800;">{$headline}</h1>
        <p style="font-size:15px;line-height:1.65;color:#334155;margin:0 0 22px;">{$text}</p>
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f8fafc;border:1px solid #eef2f7;border-radius:12px;padding:10px 18px;margin-bottom:24px;">
          {$rowsHtml}
        </table>
        {$button}
        {$supportLine}
        <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:12px 16px;font-size:12px;line-height:1.55;color:#9a3412;margin-bottom:24px;">
          <strong>Security reminder:</strong> {$name} will never ask for your card details, OTP or password by phone, email or chat.
        </div>
      </td>
    </tr>
    <tr>
      <td style="background:#0f172a;padding:18px 26px;text-align:center;font-size:12px;line-height:1.6;color:#cbd5e1;">
        Sent by {$name}<br><span style="color:#94a3b8;">This is an automated message about your booking. Please do not reply to this email.</span>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    /**
     * Brand used in customer emails (config flight.mail_brand). Links and the
     * logo are only used when they are absolute http(s) URLs.
     *
     * @return array{name: string, color: string, logo_url: string, bookings_url: string, support_email: string, support_phone: string}
     */
    public function brand(): array
    {
        $config = (array) config('flight.mail_brand', []);
        $website = rtrim(trim((string) ($config['website_url'] ?? '')), '/');
        $website = preg_match('~^https?://~i', $website) ? $website : '';
        $color = trim((string) ($config['color'] ?? ''));
        $logo = trim((string) ($config['logo_url'] ?? '')) ?: ($website !== '' ? $website . '/images/crt-logo.png' : '');
        $supportEmail = trim((string) ($config['support_email'] ?? ''));

        return [
            'name' => trim((string) ($config['name'] ?? '')) ?: 'CRT Travels',
            'color' => preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : '#F57C00',
            // Mail clients block plain-http and localhost images; fall back to an initial badge.
            'logo_url' => str_starts_with(strtolower($logo), 'https://') ? $logo : '',
            'bookings_url' => $website !== '' ? $website . '/customer/bookings' : '',
            'support_email' => filter_var($supportEmail, FILTER_VALIDATE_EMAIL) ? $supportEmail : '',
            'support_phone' => trim((string) ($config['support_phone'] ?? '')),
        ];
    }
}
