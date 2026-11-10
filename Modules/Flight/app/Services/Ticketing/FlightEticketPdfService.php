<?php

namespace Modules\Flight\Services\Ticketing;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Flight\Models\FlightBooking;
use Modules\Flight\Services\Branding\FlightTenantBrand;
use Modules\Saas\Models\Tenant;
use Throwable;

class FlightEticketPdfService
{
    private const DEFAULT_TICKET_ADVISORY = "Passengers should arrive at the departure terminal at least 2 hours before domestic flights and 3 hours before international flights.\nCarry valid government-issued photo identification for airport entry and security clearance.\nCheck-in counters close 60 minutes before departure and boarding gates close 20 minutes before departure.\nBaggage allowances, changes, cancellations, and refunds are subject to the selected fare family and the operating carrier's conditions of carriage.";

    /**
     * Generate E-Ticket PDF binary for a confirmed and ticketed booking.
     */
    public function generate(FlightBooking $booking): string
    {
        $booking->loadMissing(['passengers.ticket', 'tickets', 'ancillaries', 'flightInventory.airline', 'flightInventory.originAirport', 'flightInventory.destinationAirport']);

        $html = $this->renderHtml($booking);

        // 1. Direct Chrome / Edge Headless CLI (Fastest, 100% Vector Quality, Full CSS/SVG Support)
        $chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
        if (! file_exists($chromePath)) {
            $chromePath = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
        }

        if (file_exists($chromePath)) {
            try {
                $tempHtml = tempnam(sys_get_temp_dir(), 'etkt_h_') . '.html';
                $tempPdf = tempnam(sys_get_temp_dir(), 'etkt_p_') . '.pdf';
                file_put_contents($tempHtml, $html);

                $cmd = sprintf('"%s" --headless=new --disable-gpu --no-pdf-header-footer --print-to-pdf="%s" "%s" 2>&1', $chromePath, $tempPdf, $tempHtml);
                exec($cmd, $output, $returnCode);

                if ($returnCode === 0 && file_exists($tempPdf) && filesize($tempPdf) > 1000) {
                    $pdfContent = file_get_contents($tempPdf);
                    @unlink($tempHtml);
                    @unlink($tempPdf);
                    $this->storePdfIfConfigured($booking, $pdfContent);
                    return $pdfContent;
                }
                @unlink($tempHtml);
                @unlink($tempPdf);
            } catch (Throwable $e) {
                Log::warning('Chrome CLI PDF generation failed: ' . $e->getMessage());
            }
        }

        // Pure PHP PDF-1.4 Standard Generation Fallback (Guaranteed to produce valid PDF bytes)
        $pdfContent = $this->generatePurePdf($booking);
        $this->storePdfIfConfigured($booking, $pdfContent);

        return $pdfContent;
    }

    /**
     * Render authentic, professional 2-page E-Ticket layout.
     */
    public function renderHtml(FlightBooking $booking): string
    {
        $advisoryLines = $this->ticketAdvisory($booking);
        $advisoryHtml = implode("\n", array_map(
            fn (string $line): string => '<p>' . htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>',
            $advisoryLines
        ));
        // The logo the agency uploaded in System Settings → Logo. Without one, the
        // agency's name is printed as text (set below, once the name is known).
        $brandLogo = app(FlightTenantBrand::class)->logoDataUri($booking->tenant_id ? (int) $booking->tenant_id : null);
        // Escaped: it can be a URL typed in at tenant creation, not only a data URI.
        $brandLogo = $brandLogo ? htmlspecialchars($brandLogo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : null;
        $brandLogoPage1 = $brandLogo ? '<img src="' . $brandLogo . '" alt="Logo" style="height:56px;width:56px;object-fit:contain;border-radius:50%;">' : null;
        $brandLogoPage2 = $brandLogo ? '<img src="' . $brandLogo . '" alt="Logo" style="height:44px;width:44px;object-fit:contain;border-radius:50%;">' : null;
        // Every value on the ticket comes from the booking itself (ticketData).
        // Nothing is invented: a missing value shows as "Not assigned",
        // "Not yet issued" or similar instead of a made-up seat, time or price.
        $t = array_map(
            fn ($value) => is_string($value) ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value,
            $this->ticketData($booking)
        );

        $pnr = $t['pnr'];
        $bookingRef = $t['booking_reference'];
        $originCode = $t['origin_code'];
        $originCity = $t['origin_city'];
        $originName = $t['origin_name'];
        $destCode = $t['dest_code'];
        $destCity = $t['dest_city'];
        $destName = $t['dest_name'];
        $destTerminalHtml = $t['dest_terminal'] !== '' ? '<div class="airport-title">Terminal: ' . $t['dest_terminal'] . '</div>' : '';
        $originTerminalHtml = $t['origin_terminal'] !== '' ? '<div class="airport-title">Terminal: ' . $t['origin_terminal'] . '</div>' : '';
        $flightNumber = $t['flight_number'];
        $airlineName = $t['airline_name'];
        $cabin = $t['cabin'];
        $currency = $t['currency'];
        $totalAmount = $t['total_amount'];
        $baseFare = $t['base_fare'];
        $taxes = $t['taxes'];
        $issueDate = $t['issue_date'];
        $depTime = $t['dep_time'];
        $arrTime = $t['arr_time'];
        $depDate = $t['dep_date'];
        $arrDate = $t['arr_date'];
        $flightDurationHtml = $t['duration'] !== '—' ? '<div><span class="sub-lbl">Flight duration:</span> ' . $t['duration'] . '</div>' : '';
        $paxFullName = $t['passenger_name'];
        $paxType = $t['passenger_type'];
        $ticketNumber = $t['ticket_number'];
        $seatNum = $t['seat'];
        $mealPref = $t['meal'];
        $baggageText = $t['baggage'];
        $fareBasisHtml = $t['fare_basis'] !== '—' ? '<div><span class="sub-lbl">Fare:</span> ' . $t['fare_basis'] . '</div>' : '';
        $bookingStatus = $t['booking_status'];
        $paymentMode = $t['payment_mode'];
        $agencyName = $t['agency_name'];
        $agencyNameUpper = mb_strtoupper($t['agency_name']);
        $agencyContactHtml = implode('', array_filter([
            $t['agency_phone'] !== '' ? '<div>Phone: ' . $t['agency_phone'] . '</div>' : '',
            $t['agency_email'] !== '' ? '<div>Email: ' . $t['agency_email'] . '</div>' : '',
        ]));
        $brandLogoPage1 ??= '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:20px;font-weight:900;color:#0369a1;">' . $agencyName . '</div>';
        $brandLogoPage2 ??= '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:17px;font-weight:900;color:#0369a1;">' . $agencyName . '</div>';
        $manageBookingHtml = $t['manage_url'] !== ''
            ? '<p>View or download this ticket any time under My Bookings at <a href="' . $t['manage_url'] . '" target="_blank">' . $t['manage_label'] . '</a>.</p>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ELECTRONIC TICKET ITINERARY / RECEIPT - {$pnr}</title>
    <style>
        @page {
            size: A4;
            margin: 0;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #111827;
            margin: 0;
            padding: 0;
            background: #ffffff;
            font-size: 11px;
            line-height: 1.4;
        }
        .page {
            width: 210mm;
            min-height: 297mm;
            padding: 14mm 16mm;
            margin: 0 auto;
            background: #ffffff;
            position: relative;
            page-break-after: always;
            break-after: page;
        }
        .page-2 {
            page-break-after: avoid;
            break-after: avoid;
        }
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            border-bottom: 2px solid #0284c7;
            padding-bottom: 10px;
        }
        .crt-logo-wrap {
            display: flex;
            align-items: center;
        }
        .alliance-header {
            text-align: right;
        }
        .alliance-title {
            font-size: 11.5px;
            font-weight: bold;
            color: #0369a1;
            letter-spacing: 0.5px;
        }
        .alliance-sub {
            font-size: 10px;
            color: #475569;
            font-weight: 600;
            margin-top: 2px;
        }
        .meta-grid {
            display: flex;
            justify-content: space-between;
            font-size: 10.5px;
            line-height: 1.5;
            margin-bottom: 8px;
            color: #1f2937;
        }
        .meta-col {
            display: flex;
            flex-direction: column;
        }
        .meta-col.right {
            text-align: right;
        }
        .bold {
            font-weight: bold;
            color: #0f172a;
        }
        .grey-banner {
            background-color: #e2e8f0 !important;
            color: #0f172a;
            font-weight: bold;
            font-size: 12.5px;
            padding: 6px 10px;
            margin: 8px 0 10px;
            letter-spacing: 0.5px;
            border-top: 1px solid #cbd5e1;
            border-bottom: 1px solid #cbd5e1;
            text-align: center;
        }
        .advisory {
            font-size: 9.5px;
            color: #475569;
            margin-bottom: 10px;
            line-height: 1.4;
        }
        .advisory p {
            margin: 2px 0;
        }
        .advisory a, .notes-body a {
            color: #0284c7;
            text-decoration: underline;
        }
        .flight-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
            border: 1px solid #cbd5e1;
        }
        .flight-table th {
            background-color: #f1f5f9 !important;
            text-align: left;
            padding: 7px 10px;
            font-size: 11px;
            font-weight: bold;
            color: #1e293b;
            border-bottom: 1px solid #cbd5e1;
        }
        .flight-row td {
            padding: 8px 10px 4px;
            vertical-align: top;
            background: #ffffff;
        }
        .city-title {
            font-weight: bold;
            font-size: 11.5px;
            color: #0f172a;
        }
        .airport-title {
            font-size: 10px;
            color: #334155;
        }
        .flight-code {
            font-weight: bold;
            font-size: 12px;
            color: #0284c7;
        }
        .time-bold {
            font-weight: bold;
            font-size: 11.5px;
            color: #0f172a;
        }
        .date-sub {
            font-size: 9.5px;
            color: #475569;
        }
        .sub-details-cell {
            padding: 6px 10px 10px;
            border-bottom: 1px dashed #cbd5e1;
            background: #fafafa;
        }
        .sub-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr 1fr;
            font-size: 10px;
            line-height: 1.55;
            color: #1e293b;
        }
        .sub-lbl {
            color: #475569;
            font-weight: 600;
        }
        .ssr-line {
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px dotted #cbd5e1;
            font-size: 10px;
            color: #0f172a;
            font-weight: 500;
        }
        .table-footnotes {
            font-size: 8.5px;
            color: #64748b;
            margin: 4px 0 12px;
        }
        .finance-grid {
            display: grid;
            grid-template-columns: 1.1fr 1fr;
            gap: 20px;
            margin-bottom: 12px;
            border-top: 1px solid #e2e8f0;
            padding-top: 8px;
        }
        .finance-h4 {
            font-weight: bold;
            font-size: 11px;
            color: #0f172a;
            margin: 0 0 6px;
            letter-spacing: 0.4px;
        }
        .finance-row {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            line-height: 1.5;
            color: #334155;
        }
        .total-row {
            border-top: 1.5px solid #0f172a;
            margin-top: 6px;
            padding-top: 4px;
            font-size: 12px;
            font-weight: bold;
            color: #0f172a;
        }
        .hazard-box {
            border: 1px solid #cbd5e1;
            padding: 8px 10px;
            margin-top: 8px;
            background-color: #ffffff;
            border-radius: 4px;
        }
        .hazard-p {
            font-size: 9px;
            margin: 0 0 6px;
            line-height: 1.35;
            color: #334155;
        }
        .hazard-icons {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 4px;
            margin-top: 4px;
        }
        .icon-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            width: 48px;
        }
        .icon-circle {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            border: 1.5px solid #dc2626;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 2px;
            position: relative;
        }
        .icon-circle::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 1.5px;
            background: #dc2626;
            transform: rotate(-45deg);
        }
        .icon-circle svg {
            width: 14px;
            height: 14px;
        }
        .icon-label {
            font-size: 7.5px;
            line-height: 1.1;
            color: #475569;
        }
        /* PAGE 2 */
        .notes-body {
            font-size: 9.5px;
            line-height: 1.45;
            color: #1e293b;
        }
        .configured-advisory p {
            margin: 0 0 8px;
            white-space: pre-wrap;
        }
        .notes-h4 {
            font-weight: bold;
            font-size: 11px;
            margin: 8px 0 4px;
            color: #0f172a;
        }
        .notes-ul {
            padding-left: 16px;
            margin: 4px 0;
        }
        .notes-ul li {
            margin-bottom: 4px;
        }
        .closing-box {
            border: 1px solid #0284c7;
            background: #f0f9ff;
            padding: 8px 12px;
            margin: 10px 0;
            max-width: 480px;
            font-size: 10px;
            border-radius: 4px;
        }
        .closing-row {
            display: flex;
            justify-content: space-between;
            line-height: 1.5;
        }
        .closure-bold {
            border-top: 1px dashed #0284c7;
            margin-top: 4px;
            padding-top: 3px;
            font-weight: bold;
            color: #0369a1;
        }
        .wish-text {
            margin: 12px 0 6px;
            font-weight: bold;
            font-size: 10.5px;
            color: #0369a1;
        }
        .sys-disclaimer {
            font-size: 8.5px;
            font-weight: bold;
            color: #64748b;
            margin-bottom: 14px;
        }
        .alliance-bottom-banner {
            border: 1px solid #cbd5e1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 16px;
            background: #f8fafc !important;
            margin-top: 14px;
            border-radius: 4px;
        }
        .banner-slogan {
            font-size: 12px;
            font-weight: 700;
            line-height: 1.25;
        }
        .banner-credits {
            text-align: right;
            font-size: 9px;
            color: #64748b;
        }
    </style>
</head>
<body>
    <!-- ================= PAGE 1 ================= -->
    <div class="page">
        <!-- Top Branding Header -->
        <div class="header-top">
            <div class="crt-logo-wrap">
                {$brandLogoPage1}
            </div>
            <div class="alliance-header">
                <div class="alliance-title">ELECTRONIC TICKET RECEIPT</div>
                <div class="alliance-sub">OPERATED BY: {$airlineName}</div>
            </div>
        </div>

        <!-- Passenger & Meta Info -->
        <div class="meta-grid">
            <div class="meta-col">
                <div><span>PASSENGER:</span> <span class="bold">{$paxFullName} ({$paxType})</span></div>
                <div><span>BOOKING REFERENCE:</span> <span class="bold">{$pnr}</span></div>
                <div><span>TICKET NUMBER:</span> <span class="bold">{$ticketNumber}</span></div>
            </div>
            <div class="meta-col right">
                <div><span>Issuing office:</span></div>
                <div><span class="bold">{$agencyNameUpper}</span></div>
                <div style="margin-top: 3px;"><span>Date of issue:</span> <span class="bold">{$issueDate}</span></div>
            </div>
        </div>

        <!-- Title Banner -->
        <div class="grey-banner">
            ELECTRONIC TICKET ITINERARY / RECEIPT
        </div>

        <!-- Advisory -->
        <div class="advisory">
            <p>You must present this receipt along with a valid government photo identification (Passport / Aadhaar / National ID) to enter the airport.</p>
            {$manageBookingHtml}
        </div>

        <!-- Flight Itinerary Table -->
        <table class="flight-table">
            <thead>
                <tr>
                    <th style="width: 28%;">From</th>
                    <th style="width: 32%;">To</th>
                    <th style="width: 14%;">Flight</th>
                    <th style="width: 13%;">Departure</th>
                    <th style="width: 13%;">Arrival</th>
                </tr>
            </thead>
            <tbody>
                <tr class="flight-row">
                    <td>
                        <div class="city-title">{$originCity}</div>
                        <div class="airport-title">{$originName} ({$originCode})</div>
                        {$originTerminalHtml}
                    </td>
                    <td>
                        <div class="city-title">{$destCity}</div>
                        <div class="airport-title">{$destName} ({$destCode})</div>
                        {$destTerminalHtml}
                    </td>
                    <td>
                        <div class="flight-code">{$flightNumber}</div>
                        <div class="airport-title">{$airlineName}</div>
                    </td>
                    <td>
                        <div class="time-bold">{$depTime}</div>
                        <div class="date-sub">{$depDate}</div>
                    </td>
                    <td>
                        <div class="time-bold">{$arrTime}</div>
                        <div class="date-sub">{$arrDate}</div>
                    </td>
                </tr>
                <tr>
                    <td colspan="5" class="sub-details-cell">
                        <div class="sub-grid">
                            <div>
                                <div><span class="sub-lbl">Class:</span> {$cabin}</div>
                                <div><span class="sub-lbl">Baggage:</span> {$baggageText}</div>
                                {$fareBasisHtml}
                            </div>
                            <div>
                                <div><span class="sub-lbl">Operated by:</span> {$airlineName}</div>
                                <div><span class="sub-lbl">Issued by:</span> {$agencyNameUpper}</div>
                            </div>
                            <div>
                                <div><span class="sub-lbl">Booking status:</span> {$bookingStatus}</div>
                                <div><span class="sub-lbl">Seat:</span> {$seatNum}</div>
                                {$flightDurationHtml}
                            </div>
                        </div>
                        <div class="ssr-line">
                            <span class="sub-lbl">Meal request:</span> {$mealPref}
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
        <div class="table-footnotes">
            Seats are allocated at check-in unless a seat number is shown above. Baggage allowance is as per the fare rules of this booking.
        </div>

        <!-- Payment & Fare Breakdown -->
        <div class="finance-grid">
            <div>
                <div class="finance-h4">PAYMENT DETAILS</div>
                <div class="finance-row">
                    <span>Payment Mode:</span>
                    <span>{$paymentMode}</span>
                </div>
                <div class="finance-row">
                    <span>Reference:</span>
                    <span>{$bookingRef}</span>
                </div>
            </div>
            <div>
                <div class="finance-h4">FARE DETAILS</div>
                <div class="finance-row">
                    <span>Base Fare:</span>
                    <span>{$currency} {$baseFare}</span>
                </div>
                <div class="finance-row">
                    <span>Taxes & Airport Surcharges:</span>
                    <span>{$currency} {$taxes}</span>
                </div>
                <div class="finance-row total-row">
                    <span>Total Amount:</span>
                    <span>{$currency} {$totalAmount}</span>
                </div>
            </div>
        </div>

        <!-- Dangerous Goods Advisory -->
        <div class="hazard-box">
            <div class="hazard-p">
                <strong>DANGEROUS GOODS PROHIBITION:</strong> The carriage of certain hazardous materials, such as aerosols, fireworks, flammable liquids, and toxic items aboard the aircraft is forbidden by international civil aviation regulations.
            </div>
            <div class="hazard-icons">
                <div class="icon-item">
                    <div class="icon-circle">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><path d="M12 2L2 22h20L12 2z"/></svg>
                    </div>
                    <span class="icon-label">Explosives</span>
                </div>
                <div class="icon-item">
                    <div class="icon-circle">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><path d="M12 3v18M3 12h18"/></svg>
                    </div>
                    <span class="icon-label">Gases</span>
                </div>
                <div class="icon-item">
                    <div class="icon-circle">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
                    </div>
                    <span class="icon-label">Flammable</span>
                </div>
                <div class="icon-item">
                    <div class="icon-circle">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg>
                    </div>
                    <span class="icon-label">Oxidizers</span>
                </div>
                <div class="icon-item">
                    <div class="icon-circle">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><path d="M9 12l2 2 4-4"/></svg>
                    </div>
                    <span class="icon-label">Toxic</span>
                </div>
                <div class="icon-item">
                    <div class="icon-circle">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><path d="M12 2v20"/></svg>
                    </div>
                    <span class="icon-label">Radioactive</span>
                </div>
                <div class="icon-item">
                    <div class="icon-circle">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><path d="M3 12h18"/></svg>
                    </div>
                    <span class="icon-label">Corrosive</span>
                </div>
                <div class="icon-item">
                    <div class="icon-circle">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><rect x="4" y="6" width="16" height="12" rx="2"/></svg>
                    </div>
                    <span class="icon-label">Lithium Batt</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= PAGE 2 ================= -->
    <div class="page page-2">
        <!-- Top Page 2 Header -->
        <div class="header-top">
            <div class="crt-logo-wrap">
                {$brandLogoPage2}
            </div>
            <div class="alliance-header">
                <div class="alliance-title">TRAVEL REGULATIONS & NOTES</div>
                <div class="alliance-sub">PNR: {$pnr} &bull; PAGE 2 OF 2</div>
            </div>
        </div>

        <div class="grey-banner">
            IMPORTANT TRAVEL ADVISORY & CARRIER CONDITIONS
        </div>

        <div class="notes-body">
            <div class="configured-advisory">{$advisoryHtml}</div>

            <div class="wish-text">
                Thank you for choosing {$agencyName}. We wish you a safe and pleasant flight!
            </div>

            <div class="sys-disclaimer">
                THIS IS A COMPUTER-GENERATED ELECTRONIC TICKET RECEIPT. NO PHYSICAL SIGNATURE IS REQUIRED.
            </div>

            <div class="alliance-bottom-banner">
                <div class="banner-slogan" style="color: #0369a1;">
                    {$agencyNameUpper}<br><span style="font-size: 10px; color: #64748b; font-weight: normal;">Issued by {$agencyName}</span>
                </div>
                <div class="banner-credits">
                    {$agencyContactHtml}
                </div>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Everything printed on the e-ticket, read from the booking, its fare,
     * inventory, passengers and tickets, plus the agency's name and onboarding
     * contact. Values are raw (not HTML-escaped). When something is genuinely
     * unknown it is labelled as such ("Not assigned", "Not yet issued", "—")
     * instead of being filled with a sample value.
     *
     * @return array<string, string>
     */
    public function ticketData(FlightBooking $booking): array
    {
        $booking->loadMissing(['passengers.ticket', 'tickets', 'flightFare.fareFamily', 'flightInventory.airline', 'flightInventory.originAirport', 'flightInventory.destinationAirport']);

        $itinerary = $booking->itinerary ?? [];
        if (is_string($itinerary)) {
            $itinerary = json_decode($itinerary, true) ?? [];
        }
        $fareSnapshot = $booking->fare_snapshot ?? [];
        if (is_string($fareSnapshot)) {
            $fareSnapshot = json_decode($fareSnapshot, true) ?? [];
        }
        $pricing = $booking->pricing_breakdown ?? [];
        if (is_string($pricing)) {
            $pricing = json_decode($pricing, true) ?? [];
        }
        $inventory = $booking->flightInventory;
        $dash = '—';

        $upper = fn ($value): string => mb_strtoupper(trim((string) $value));
        $first = function (...$values): string {
            foreach ($values as $value) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }

            return '';
        };

        // Route
        // Itineraries store the route either as origin/destination objects or as
        // flat departure_airport / arrival_airport codes.
        $place = function (string $key, string $flatKey, $airport) use ($itinerary, $first, $upper): array {
            $node = $itinerary[$key] ?? ($itinerary[$flatKey] ?? null);
            if (is_string($node)) {
                $node = ['code' => $node];
            }
            $node = is_array($node) ? $node : [];

            return [
                'code' => $upper($first($node['code'] ?? null, $node['iata_code'] ?? null, $airport?->iata_code)),
                'city' => $upper($first($node['city'] ?? null, $airport?->city)),
                'name' => $upper($first($node['name'] ?? null, $airport?->name)),
                'terminal' => $first($node['terminal'] ?? null),
            ];
        };
        $origin = $place('origin', 'departure_airport', $inventory?->originAirport);
        $dest = $place('destination', 'arrival_airport', $inventory?->destinationAirport);

        $airline = $itinerary['airline'] ?? null;
        $airlineName = $upper($first(is_array($airline) ? ($airline['name'] ?? null) : $airline, $itinerary['airline_name'] ?? null, $inventory?->airline?->name));
        $flightNumber = $first($itinerary['flight_number'] ?? null, $inventory?->flight_number);

        // Dates and times (H:i), with the arrival date rolled to the next day for
        // overnight flights when no arrival date is stored.
        $clock = function ($value): string {
            $value = trim((string) $value);
            if ($value === '') {
                return '';
            }
            if (preg_match('/(\d{1,2}):(\d{2})/', $value, $m)) {
                return sprintf('%02d:%s', (int) $m[1], $m[2]);
            }

            return '';
        };
        $depTime = $clock($first($itinerary['departure_time'] ?? null, $itinerary['departure']['time'] ?? null, $inventory?->departure_time));
        $arrTime = $clock($first($itinerary['arrival_time'] ?? null, $itinerary['arrival']['time'] ?? null, $inventory?->arrival_time));
        $depDateRaw = $first($itinerary['departure_date'] ?? null, $itinerary['departure']['date'] ?? null, data_get($booking->meta, 'departure_date'), data_get($booking->hold?->meta, 'departure_date'));
        $arrDateRaw = $first($itinerary['arrival_date'] ?? null, $itinerary['arrival']['date'] ?? null);
        $depTs = $depDateRaw !== '' ? strtotime(substr($depDateRaw, 0, 10)) : false;
        $arrTs = $arrDateRaw !== '' ? strtotime(substr($arrDateRaw, 0, 10)) : false;
        if (! $arrTs && $depTs) {
            $arrTs = ($depTime !== '' && $arrTime !== '' && $arrTime < $depTime) ? strtotime('+1 day', $depTs) : $depTs;
        }
        $fmtDate = fn ($ts): string => $ts ? date('d M Y', $ts) : '';

        // Only a stored duration: departure and arrival are local times in
        // different time zones, so subtracting them would give a wrong figure.
        $duration = $first($itinerary['duration'] ?? null);

        $issuedAt = $booking->ticketed_at ?: $booking->confirmed_at ?: $booking->created_at;

        // Passenger and ticket
        $pax = $booking->passengers->first();
        $paxType = $pax?->passenger_type;
        $paxType = $upper(is_object($paxType) && property_exists($paxType, 'value') ? $paxType->value : $paxType);
        $ticketNumber = $first($pax?->ticket?->ticket_number, $booking->tickets->first()?->ticket_number);
        $seat = $first($pax?->ticket?->seat_number, $pax?->seat_preference);
        $meal = $first($pax?->meal_preference);

        // Baggage: the booked fare family's allowance, then the fare, then the snapshot.
        $familyBaggage = (array) ($booking->flightFare?->fareFamily?->baggage_allowance ?? []);
        $fareBaggage = (array) ($booking->flightFare?->baggage ?? []);
        $rules = (array) ($fareSnapshot['rules'] ?? []);
        $inventoryBaggage = (array) ($inventory?->baggage_policy ?? []);
        $kgOrNull = function (...$values): ?float {
            foreach ($values as $value) {
                if ($value !== null && $value !== '' && is_numeric($value)) {
                    return (float) $value;
                }
            }

            return null;
        };
        $checkinKg = $kgOrNull($familyBaggage['checkin_kg'] ?? null, $fareBaggage['checkin_kg'] ?? null, $rules['checkin_kg'] ?? null, $rules['baggage_kg'] ?? null, $inventoryBaggage['checkin_kg'] ?? null);
        $cabinKg = $kgOrNull($familyBaggage['cabin_kg'] ?? null, $fareBaggage['cabin_kg'] ?? null, $rules['cabin_kg'] ?? null, $inventoryBaggage['cabin_kg'] ?? null);
        $kgText = fn (?float $kg): string => $kg === null ? '' : ($kg <= 0 ? 'None' : rtrim(rtrim(number_format($kg, 1, '.', ''), '0'), '.') . ' kg');
        $baggageParts = array_filter([
            $checkinKg !== null ? 'Check-in: ' . $kgText($checkinKg) : '',
            $cabinKg !== null ? 'Cabin: ' . $kgText($cabinKg) : '',
        ]);

        $fareFamilyName = $first($booking->flightFare?->fareFamily?->name, $fareSnapshot['fare_family']['name'] ?? null);
        $fareBasis = $first($fareSnapshot['fare_code'] ?? null, $booking->flightFare?->fare_basis_code, $fareFamilyName);

        // Prices: only what the booking recorded; no invented base/tax split.
        $currency = $first($booking->currency, 'INR');
        $money = fn ($amount): string => number_format((float) $amount, 0, '.', ',');
        $total = (float) ($booking->total_amount ?? 0);
        $baseFare = $pricing['base_fare'] ?? null;
        $taxAmount = isset($pricing['total_taxes'])
            ? (float) $pricing['total_taxes']
            : (isset($pricing['taxes']) || isset($pricing['gst']) ? (float) ($pricing['taxes'] ?? 0) + (float) ($pricing['gst'] ?? 0) : null);

        // Statuses as recorded
        $statusValue = fn ($status): string => is_object($status) && property_exists($status, 'value') ? (string) $status->value : (string) $status;
        $bookingStatus = str_replace('_', ' ', $upper($statusValue($booking->booking_status)));
        $paymentStatus = $upper($statusValue($booking->payment_status));

        $agency = $this->agencyDetails($booking);

        return [
            'pnr' => $first($booking->pnr, $booking->supplier_pnr, $itinerary['pnr'] ?? null, $itinerary['pnr_number'] ?? null) ?: 'Pending',
            'booking_reference' => $first($booking->booking_reference) ?: $dash,
            'origin_code' => $origin['code'] ?: $dash,
            'origin_city' => $origin['city'] ?: ($origin['code'] ?: $dash),
            'origin_name' => $origin['name'] ?: ($origin['code'] ?: $dash),
            'origin_terminal' => $origin['terminal'],
            'dest_code' => $dest['code'] ?: $dash,
            'dest_city' => $dest['city'] ?: ($dest['code'] ?: $dash),
            'dest_name' => $dest['name'] ?: ($dest['code'] ?: $dash),
            'dest_terminal' => $dest['terminal'],
            'flight_number' => $flightNumber ?: $dash,
            'airline_name' => $airlineName ?: $dash,
            'cabin' => $upper($first($itinerary['cabin_class'] ?? null, $rules['cabin_class'] ?? null, $inventory?->cabin_class)) ?: $dash,
            'currency' => $currency,
            'total_amount' => $money($total),
            'base_fare' => $baseFare !== null ? $money($baseFare) : $dash,
            'taxes' => $taxAmount !== null ? $money($taxAmount) : $dash,
            'issue_date' => $issuedAt ? date('d M Y', strtotime((string) $issuedAt)) : $dash,
            'dep_time' => $depTime ?: $dash,
            'arr_time' => $arrTime ?: $dash,
            'dep_date' => $fmtDate($depTs),
            'arr_date' => $fmtDate($arrTs),
            'duration' => $duration ?: $dash,
            'passenger_name' => $upper($first($pax?->full_name, $booking->contact_name)) ?: $dash,
            'passenger_type' => $paxType ?: 'ADULT',
            'ticket_number' => $ticketNumber !== '' ? str_replace('-', ' ', $ticketNumber) : 'Pending',
            'seat' => $seat !== '' ? $upper($seat) : 'Not assigned (at check-in)',
            'meal' => $meal !== '' ? $upper($meal) : 'None requested',
            'baggage' => $baggageParts ? implode(' / ', $baggageParts) : 'As per fare rules',
            'fare_basis' => $fareBasis !== '' ? $upper($fareBasis) : $dash,
            'booking_status' => $bookingStatus ?: $dash,
            'payment_mode' => $paymentStatus === 'PAID' ? 'Paid online' : ($paymentStatus !== '' ? ucfirst(strtolower($paymentStatus)) : $dash),
            'agency_name' => $agency['name'],
            'agency_email' => $agency['email'],
            'agency_phone' => $agency['phone'],
            'manage_url' => $agency['manage_url'],
            'manage_label' => $agency['manage_label'],
        ];
    }

    /**
     * The issuing agency: its App Name setting (falls back to the onboarding
     * name), onboarding contact email/phone, and its customer site's My Bookings
     * link when it has its own domain.
     *
     * @return array{name: string, email: string, phone: string, manage_url: string, manage_label: string}
     */
    private function agencyDetails(FlightBooking $booking): array
    {
        $tenant = $booking->tenant_id
            ? Tenant::query()->withoutGlobalScopes()->find($booking->tenant_id, ['id', 'name', 'domain', 'contact_email', 'contact_phone'])
            : null;

        $name = $tenant ? $this->tenantAppName((int) $tenant->id) : '';
        if ($name === '') {
            $name = trim((string) ($tenant?->name ?? ''));
        }
        if ($name === '') {
            $name = trim((string) config('app.name', 'Flight Booking'));
        }

        $domain = trim((string) ($tenant?->domain ?? ''));
        $domain = preg_replace('#^https?://#i', '', rtrim($domain, '/')) ?? '';

        return [
            'name' => $name,
            'email' => trim((string) ($tenant?->contact_email ?? '')),
            'phone' => trim((string) ($tenant?->contact_phone ?? '')),
            'manage_url' => $domain !== '' ? (request()?->isSecure() ? 'https' : 'http') . '://' . $domain . '/customer/bookings' : '',
            'manage_label' => $domain,
        ];
    }

    /** The tenant's App Name setting as plain text ('' when unset). */
    private function tenantAppName(int $tenantId): string
    {
        try {
            $context = app(\Modules\Saas\Support\TenantContext::class);
            $settings = app(\Modules\Setting\Services\Setting\SettingServiceInterface::class);
            $previous = $context->id();
            $switch = (int) $previous !== $tenantId;
            if ($switch) {
                $context->setId($tenantId);
                $settings->refreshSettingBinding();
            }
            try {
                $value = setting('app_name');
            } finally {
                if ($switch) {
                    $context->setId($previous);
                    $settings->refreshSettingBinding();
                }
            }
        } catch (Throwable) {
            return '';
        }

        if (is_array($value)) {
            $value = $value[app()->getLocale()]
                ?? $value[(string) config('app.fallback_locale')]
                ?? collect($value)->first(fn ($text) => is_string($text) && trim($text) !== '');
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Pure PHP standard PDF-1.4 generator fallback.
     */
    public function generatePurePdf(FlightBooking $booking): string
    {
        // Same booking data as the HTML ticket; nothing invented.
        $d = array_map(fn ($value) => is_string($value) ? $this->pdfEscape($value) : $value, $this->ticketData($booking));
        $agency = mb_strtoupper($d['agency_name']);
        $lines = [
            [16, "{$agency} - ELECTRONIC TICKET ITINERARY / RECEIPT"],
            [11, "PNR: {$d['pnr']}   |   Reference: {$d['booking_reference']}"],
            [11, "Flight: {$d['flight_number']} {$d['airline_name']}   |   {$d['origin_city']} ({$d['origin_code']}) to {$d['dest_city']} ({$d['dest_code']})"],
            [11, "Departs: {$d['dep_date']} {$d['dep_time']}   |   Arrives: {$d['arr_date']} {$d['arr_time']}"],
            [11, "Passenger: {$d['passenger_name']}   |   Ticket: {$d['ticket_number']}   |   Seat: {$d['seat']}"],
            [11, "Baggage: {$d['baggage']}   |   Class: {$d['cabin']}"],
            [11, "Total: {$d['currency']} {$d['total_amount']}   |   Status: {$d['booking_status']}"],
            [8, 'Carriage of hazardous materials aboard aircraft is forbidden by civil aviation regulations.'],
        ];
        $textStream1 = '';
        $y = 800;
        foreach ($lines as [$size, $text]) {
            $textStream1 .= "BT /F1 {$size} Tf 50 {$y} Td ({$text}) Tj ET\n";
            $y -= $size >= 16 ? 40 : 25;
        }

        $textStream2 = "BT /F1 14 Tf 50 800 Td (IMPORTANT TRAVEL ADVISORY & CARRIER CONDITIONS) Tj ET\n";
        $lineY = 765;
        foreach (array_slice($this->ticketAdvisory($booking), 0, 28) as $line) {
            foreach (explode("\n", wordwrap($line, 88, "\n", true)) as $wrappedLine) {
                if ($lineY < 70) {
                    break 2;
                }
                $safeLine = $this->pdfEscape($wrappedLine);
                $textStream2 .= "BT /F1 10 Tf 50 {$lineY} Td ({$safeLine}) Tj ET\n";
                $lineY -= 22;
            }
            $lineY -= 6;
        }

        $objects = [
            1 => '<</Type /Catalog /Pages 2 0 R>>',
            2 => '<</Type /Pages /Kids [3 0 R 4 0 R] /Count 2>>',
            3 => '<</Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 5 0 R /Resources <</Font <</F1 7 0 R>>>>>>',
            4 => '<</Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 6 0 R /Resources <</Font <</F1 7 0 R>>>>>>',
            5 => '<< /Length ' . strlen($textStream1) . " >>\nstream\n{$textStream1}endstream",
            6 => '<< /Length ' . strlen($textStream2) . " >>\nstream\n{$textStream2}endstream",
            7 => '<</Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold>>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 8\n0000000000 65535 f \n";
        for ($number = 1; $number <= 7; $number++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }
        $pdf .= "trailer <</Size 8 /Root 1 0 R>>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }

    protected function storePdfIfConfigured(FlightBooking $booking, string $pdfBinary): void
    {
        try {
            $disk = config('filesystems.default', 'local');
            $ref = preg_replace('/[^A-Za-z0-9_-]/', '', $booking->booking_reference ?: 'REF');
            $path = "flight-etickets/{$ref}.pdf";
            Storage::disk($disk)->put($path, $pdfBinary);
        } catch (Throwable $e) {
            Log::info('E-ticket PDF archive store skipped: ' . $e->getMessage());
        }
    }

    public function ticketAdvisory(FlightBooking $booking): array
    {
        $tenant = $booking->tenant_id
            ? Tenant::query()->withoutGlobalScopes()->find($booking->tenant_id)
            : null;
        $text = trim((string) data_get(
            $tenant?->settings,
            'flight_booking.ticket_advisory',
            self::DEFAULT_TICKET_ADVISORY
        ));

        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: []), fn ($line) => $line !== ''));
    }

    private function pdfEscape(string $text): string
    {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
