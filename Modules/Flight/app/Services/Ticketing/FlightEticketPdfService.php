<?php

namespace Modules\Flight\Services\Ticketing;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Flight\Models\FlightBooking;
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
        $itinerary = $booking->itinerary ?? [];
        if (is_string($itinerary)) {
            $itinerary = json_decode($itinerary, true) ?? [];
        }

        $pnr = $booking->pnr ?: ($itinerary['pnr'] ?? ($itinerary['pnr_number'] ?? 'HG-12635'));
        $bookingRef = $booking->booking_reference ?: 'BKNG-' . time();

        // Parse Origin & Destination
        $originCode = 'BLR';
        $originCity = 'BENGALURU';
        $originName = 'KEMPEGOWDA INTL';
        $destCode = 'BOM';
        $destCity = 'MUMBAI';
        $destName = 'CHHATRAPATI S MAHARAJ';
        $destTerminal = '2';

        if (isset($itinerary['origin'])) {
            if (is_array($itinerary['origin'])) {
                $originCode = $itinerary['origin']['code'] ?? $itinerary['origin']['iata_code'] ?? 'BLR';
                $originCity = strtoupper($itinerary['origin']['city'] ?? 'BENGALURU');
                $originName = strtoupper($itinerary['origin']['name'] ?? 'KEMPEGOWDA INTL');
            } else {
                $originCode = strtoupper($itinerary['origin']);
                $originCity = $originCode;
            }
        } elseif ($booking->flightInventory?->originAirport) {
            $originCode = $booking->flightInventory->originAirport->iata_code ?? 'BLR';
            $originCity = strtoupper($booking->flightInventory->originAirport->city ?? 'BENGALURU');
            $originName = strtoupper($booking->flightInventory->originAirport->name ?? 'KEMPEGOWDA INTL');
        }

        if (isset($itinerary['destination'])) {
            if (is_array($itinerary['destination'])) {
                $destCode = $itinerary['destination']['code'] ?? $itinerary['destination']['iata_code'] ?? 'BOM';
                $destCity = strtoupper($itinerary['destination']['city'] ?? 'MUMBAI');
                $destName = strtoupper($itinerary['destination']['name'] ?? 'CHHATRAPATI S MAHARAJ');
                $destTerminal = $itinerary['destination']['terminal'] ?? '2';
            } else {
                $destCode = strtoupper($itinerary['destination']);
                $destCity = $destCode;
            }
        } elseif ($booking->flightInventory?->destinationAirport) {
            $destCode = $booking->flightInventory->destinationAirport->iata_code ?? 'BOM';
            $destCity = strtoupper($booking->flightInventory->destinationAirport->city ?? 'MUMBAI');
            $destName = strtoupper($booking->flightInventory->destinationAirport->name ?? 'CHHATRAPATI S MAHARAJ');
        }

        $flightNumber = $itinerary['flight_number'] ?? ($booking->flightInventory?->flight_number ?? 'AI604');
        $airlineName = 'AIR INDIA';
        if (isset($itinerary['airline'])) {
            $airlineName = is_array($itinerary['airline']) ? strtoupper($itinerary['airline']['name'] ?? 'AIR INDIA') : strtoupper($itinerary['airline']);
        } elseif ($booking->flightInventory?->airline) {
            $airlineName = strtoupper($booking->flightInventory->airline->name ?? 'AIR INDIA');
        }

        $cabin = strtoupper($itinerary['cabin_class'] ?? ($booking->flightInventory?->cabin_class ?? 'ECONOMY'));
        $totalAmount = number_format((float) ($booking->total_amount ?: 5000), 0, '.', '');
        $currency = $booking->currency ?: 'INR';

        // Dates & Times
        $issueDate = $booking->ticketed_at ? date('dMy', strtotime((string)$booking->ticketed_at)) : ($booking->created_at ? date('dMy', strtotime((string)$booking->created_at)) : date('dMy'));
        $depTime = $itinerary['departure_time'] ?? ($booking->flightInventory?->departure_time ? date('H:i', strtotime($booking->flightInventory->departure_time)) : '08:20');
        $arrTime = $itinerary['arrival_time'] ?? ($booking->flightInventory?->arrival_time ? date('H:i', strtotime($booking->flightInventory->arrival_time)) : '10:35');
        if (strlen($depTime) > 5) $depTime = substr($depTime, 0, 5);
        if (strlen($arrTime) > 5) $arrTime = substr($arrTime, 0, 5);

        $depDate = isset($itinerary['departure_date']) ? date('dMy', strtotime($itinerary['departure_date'])) : date('dMy');
        $arrDate = isset($itinerary['arrival_date']) ? date('dMy', strtotime($itinerary['arrival_date'])) : $depDate;
        $flightDuration = $itinerary['duration'] ?? '02:15';

        // Passengers
        $firstPax = $booking->passengers->first();
        $paxFullName = strtoupper($firstPax?->full_name ?? ($booking->contact_name ?: 'GANGA BALAJI'));
        $paxType = strtoupper($firstPax?->passenger_type?->value ?? 'ADT');
        $ticketNumber = $firstPax?->ticket?->ticket_number ?? '098 1000000000';
        $ticketNumber = str_replace('-', ' ', $ticketNumber);
        $seatNum = $firstPax?->ticket?->seat_number ?? ($firstPax?->seat_preference ?? '12A');
        $mealPref = $firstPax?->meal_preference ? strtoupper($firstPax->meal_preference) . ' - CONFIRMED' : 'STANDARD IN-FLIGHT SERVICE - CONFIRMED';

        // Pricing breakdown
        $baseFareNum = (float) ($booking->pricing_breakdown['base_fare'] ?? ($booking->total_amount * 0.8));
        $taxesNum = (float) ($booking->pricing_breakdown['total_taxes'] ?? ($booking->total_amount * 0.2));
        if ($baseFareNum <= 0) $baseFareNum = round((float)$totalAmount * 0.8);
        if ($taxesNum <= 0) $taxesNum = round((float)$totalAmount * 0.2);
        $baseFare = number_format($baseFareNum, 0, '.', '');
        $taxes = number_format($taxesNum, 0, '.', '');

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
                <svg width="220" height="46" viewBox="0 0 220 46" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="22" cy="23" r="19" fill="#0284c7" />
                    <circle cx="22" cy="23" r="15" fill="#0369a1" />
                    <path d="M11 26L20 18L29 20L25 24L33 27L30 29L24 26L20 30L17 29L19 26L11 26Z" fill="#ffffff" />
                    <text x="50" y="22" font-family="'Segoe UI', Arial, sans-serif" font-size="18" font-weight="900" fill="#0369a1" letter-spacing="0.5">CRT Travels</text>
                    <text x="50" y="36" font-family="'Segoe UI', Arial, sans-serif" font-size="9.5" font-weight="600" fill="#64748b" letter-spacing="0.5">Flight Booking Platform</text>
                </svg>
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
                <div><span>CRT TRAVELS INDIA</span></div>
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
            <p>Please check flight real-time status and baggage policies at <a href="http://localhost:3101/flights" target="_blank">www.crttravels.com</a></p>
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
                    </td>
                    <td>
                        <div class="city-title">{$destCity}</div>
                        <div class="airport-title">{$destName} ({$destCode})</div>
                        <div class="airport-title">Terminal: {$destTerminal}</div>
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
                                <div><span class="sub-lbl">Baggage:</span> 15KG Check-in / 7KG Hand</div>
                                <div><span class="sub-lbl">Fare basis:</span> STANDARD-WEB</div>
                            </div>
                            <div>
                                <div><span class="sub-lbl">Operated by:</span> {$airlineName}</div>
                                <div><span class="sub-lbl">Marketed by:</span> CRT TRAVELS</div>
                            </div>
                            <div>
                                <div><span class="sub-lbl">Booking status:</span> CONFIRMED / OK</div>
                                <div><span class="sub-lbl">Seat:</span> {$seatNum}</div>
                                <div><span class="sub-lbl">Flight duration:</span> {$flightDuration}</div>
                            </div>
                        </div>
                        <div class="ssr-line">
                            <span class="sub-lbl">Special Service Request:</span> {$mealPref}
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
        <div class="table-footnotes">
            (1) OK = Confirmed Seat Guarantee &nbsp;&nbsp;&nbsp; (2) Checked baggage allowance strictly as per airline fare rules
        </div>

        <!-- Payment & Fare Breakdown -->
        <div class="finance-grid">
            <div>
                <div class="finance-h4">PAYMENT DETAILS</div>
                <div class="finance-row">
                    <span>Payment Mode:</span>
                    <span>ONLINE / GATEWAY VERIFIED</span>
                </div>
                <div class="finance-row">
                    <span>Reference:</span>
                    <span>{$bookingRef}</span>
                </div>
                <div class="finance-row">
                    <span>Endorsements:</span>
                    <span>NON-END VLD ON FLT ONLY</span>
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
                <svg width="180" height="38" viewBox="0 0 220 46" fill="none">
                    <circle cx="22" cy="23" r="19" fill="#0284c7" />
                    <circle cx="22" cy="23" r="15" fill="#0369a1" />
                    <path d="M11 26L20 18L29 20L25 24L33 27L30 29L24 26L20 30L17 29L19 26L11 26Z" fill="#ffffff" />
                    <text x="50" y="24" font-family="'Segoe UI', Arial, sans-serif" font-size="19" font-weight="900" fill="#0369a1">CRT Travels</text>
                </svg>
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
                Thank you for choosing CRT Travels. We wish you a safe and pleasant flight!
            </div>

            <div class="sys-disclaimer">
                THIS IS A COMPUTER-GENERATED ELECTRONIC TICKET RECEIPT ISSUED UNDER AIRLINE CARRIAGE REGULATIONS. NO PHYSICAL SIGNATURE IS REQUIRED.
            </div>

            <div class="alliance-bottom-banner">
                <div class="banner-slogan" style="color: #0369a1;">
                    CRT TRAVELS GLOBAL NETWORK<br><span style="font-size: 10px; color: #64748b; font-weight: normal;">Reliable, verified, seamless flight bookings worldwide.</span>
                </div>
                <div class="banner-credits">
                    <div>24/7 Support: support@crttravels.com</div>
                    <div style="font-weight: bold; color: #0369a1; font-size: 10px; margin-top: 3px;">IATA VERIFIED TICKETING</div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Pure PHP standard PDF-1.4 generator fallback.
     */
    public function generatePurePdf(FlightBooking $booking): string
    {
        $pnr = $booking->pnr ?: 'HG-12635';
        $ref = $booking->booking_reference ?: 'BKNG-' . time();
        $itinerary = $booking->itinerary ?? [];
        if (is_string($itinerary)) {
            $itinerary = json_decode($itinerary, true) ?? [];
        }

        $flightNo = $itinerary['flight_number'] ?? 'AI604';
        $origin = 'BENGALURU';
        $dest = 'MUMBAI';
        if (isset($itinerary['origin'])) {
            $origin = is_array($itinerary['origin']) ? ($itinerary['origin']['city'] ?? 'BENGALURU') : $itinerary['origin'];
        }
        if (isset($itinerary['destination'])) {
            $dest = is_array($itinerary['destination']) ? ($itinerary['destination']['city'] ?? 'MUMBAI') : $itinerary['destination'];
        }

        $total = number_format((float) ($booking->total_amount ?: 5000), 2);
        $curr = $booking->currency ?: 'INR';

        $paxName = strtoupper($booking->contact_name ?: ($booking->passengers->first()?->full_name ?: 'GANGA BALAJI'));

        $textStream1 = "BT /F1 16 Tf 50 800 Td (CRT TRAVELS - ELECTRONIC TICKET ITINERARY / RECEIPT) Tj ET\n"
            . "BT /F1 11 Tf 50 760 Td (Booking Reference: {$pnr}   |   Reference: {$ref}) Tj ET\n"
            . "BT /F1 11 Tf 50 735 Td (Flight: {$flightNo}   |   Route: {$origin} to {$dest}) Tj ET\n"
            . "BT /F1 11 Tf 50 710 Td (Passenger: {$paxName}   |   Seat: 12A) Tj ET\n"
            . "BT /F1 11 Tf 50 685 Td (Total Amount: {$curr} {$total}   |   Status: CONFIRMED / OK) Tj ET\n"
            . "BT /F1 8 Tf 50 650 Td (Carriage of hazardous materials aboard aircraft is forbidden by civil aviation regulations.) Tj ET\n";

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
