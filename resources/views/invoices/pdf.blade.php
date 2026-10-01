<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>{{ $invoice->invoice_number }}</title>

    <link rel="icon" href="{{asset('favicon.ico')}}" type="image/x-icon"/>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800&display=swap"
          rel="stylesheet">

    <style>
        /* GLOBAL */
        body {
            background: #FFFFFF;
            padding: 0;
            font-family: "DejaVu Sans", "Noto Sans", "Noto Sans Devanagari", "Arial Unicode MS", "Cairo", sans-serif;
            color: #1F2937;
            line-height: 1.55;
        }

        .page-wrapper {

        }

        .invoice-card {
            /*padding: 40px;*/
            /*background: #FFFFFF;*/
        }

        /* HEADER */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .company-block {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .company-block img {
            width: 50px;
            height: 50px;
        }

        .company-block b {
            font-size: 20px;
            font-weight: 700;
            display: block;
            margin-bottom: -4px;
        }

        .company-block span {
            font-size: 14px;
            color: #6B7280;
        }

        .invoice-title {
            text-align: right;
        }

        .invoice-title .title {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: -10px;
            letter-spacing: 1px;
            display: block;
        }

        .reference-invoice {
            font-size: 18px;
            color: #6B7280;
            font-weight: 500;
        }

        /* INFO ROW */
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 18px 0;
            border-top: 1px dashed #E5E7EB;
            border-bottom: 1px dashed #E5E7EB;
            margin-bottom: 30px;

        }

        .info-box span {
            display: block;
            font-size: 12px;
            font-weight: 500;
            color: #6B7280;
            margin-bottom: 3px;
        }

        .info-box {
            font-size: 15px;
        }

        /* PARTY SECTION */
        .party-section {
            display: flex;
            gap: 35px;
            margin-bottom: 40px;
        }

        .party-card {
            flex: 1;
        }

        .party-title {
            font-size: 1rem;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 12px;
            border-left: 4px solid #F57C00;
            padding-left: 8px;
        }


        /* QR */
        .qr-box {
            width: 120px;
            height: 120px;
            border: 1px dashed #CBD5E1;
            border-radius: 12px;
            padding: 10px;
            background: #FFFFFF;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* TABLE */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 40px;
        }

        thead th {
            background: #F8FAFC;
            padding: 12px;
            font-size: 13px;
            font-weight: bold;
            color: #4B5563;
            border-bottom: 1px solid #E5E7EB;
            text-align: left;
        }

        tbody td {
            padding: 14px 12px;
            font-size: 14px;
            border-bottom: 1px solid #F1F5F9;
        }

        tbody tr:nth-child(even) {
            background: #FCFCFC;
        }

        /* SUMMARY */
        .summary-section {
            display: flex;
            gap: 30px;
        }

        .summary-card {
            flex: 1;
            padding: 24px;
            border-radius: 14px;
            border: 1px dashed #E5E7EB;
            background: #FFFFFF;
        }

        .summary-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 15px;
            padding-bottom: 12px;
            border-bottom: 1px dashed #D1D5DB;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
            padding: 6px 0;
        }

        .summary-row strong {
            font-weight: 700;
        }

        .text-green {
            color: #10B981;
        }

        .summary-total {
            color: #10B981;
            font-size: 18px;
            font-weight: 800;
            padding-top: 10px;
        }

        .border-t-dashed {
            border-top: 1px dashed #D3DCE5;
            padding-top: 8px;
        }

        .border-b-dashed {
            border-bottom: 1px dashed #D3DCE5;
            padding-bottom: 8px;
        }

        /* FOOTER UUID */
        .uuid-text {
            margin-top: 35px;
            padding-top: 12px;
            border-top: 1px dashed #D1D5DB;
            font-size: 12px;
            color: #6B7280;
            text-align: left;
            word-break: break-all;
        }

        .uuid-title {
            font-weight: bold;
        }

        /* PRINT OPTIMIZATION */
        @media print {
            body {
                margin: 0;
                padding: 0;
            }

            .invoice-card {
                border: none;
                padding: 0;
            }
        }

        .summary-total-primary {
            display: flex;
            align-items: center;
            font-size: 18px;
            color: #F57C00;
            font-weight: 800;
        }
    </style>
</head>

<body>

<div class="page-wrapper">
    <div class="invoice-card">

        <div class="header">
            <div class="company-block">
                @if($invoiceLogo = getInvoiceLogoBase64())
                    <img alt="{{ $invoice->seller->legal_name }} logo" src="{{ $invoiceLogo }}"/>
                @endif
                &nbsp;&nbsp;
                <div>
                    <b>{{ $invoice->seller->legal_name }} - {{ $invoice->branch->name }}</b>
                    <span>{{ $invoice->seller->email }}</span>
                </div>
            </div>

            <div class="invoice-title">
                <span class="title">
                    @if($invoice->invoice_kind->isCreditNote())
                        {{ strtoupper(__("invoice::invoices.show.credit_note")) }}
                    @else
                        {{ strtoupper(__("invoice::invoices.show.invoice")) }}
                    @endif
                </span>

                @if($invoice->referenceInvoice)
                    <small class="reference-invoice">
                        {{ $invoice->referenceInvoice->invoice_number }}
                    </small>
                @endif
            </div>
        </div>

        <!-- INFO -->
        <div class="info-row">
            <div class="info-box">
                <span>@lang("invoice::invoices.show.invoice_number")</span>
                {{ $invoice->invoice_number }}
            </div>

            <div class="info-box">
                <span>@lang("invoice::invoices.show.invoice_date")</span>
                {{ dateTimeFormat($invoice->issued_at) }}
            </div>

            <div class="info-box" style="text-align:right;"></div>
        </div>

        <!-- PARTIES -->
        <div class="party-section">
            <div class="party-card">
                <div class="party-title">@lang("invoice::invoices.show.seller")</div>
                @include('invoices.partials.party', ['party' => $invoice->seller])
            </div>

            <div class="party-card">
                <div class="party-title">@lang("invoice::invoices.show.billing_address")</div>
                @include('invoices.partials.party', ['party' => $invoice->buyer])
            </div>

            <div class="qr-box">
                <img alt="qr-code" src="data:{{ $invoice->getQrcodeMimeType() }};base64,{{ $invoice->getQrcodeBase64() }}"
                     style="width:100%; height:100%;">
            </div>
        </div>

        <!-- TABLE -->
        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>@lang("invoice::invoices.show.description")</th>
                <th>@lang("invoice::invoices.show.unit_price")</th>
                <th>@lang("invoice::invoices.show.quantity")</th>
                <th>@lang("invoice::invoices.show.sub_total")</th>
                <th>@lang("invoice::invoices.show.total_tax")</th>
                <th>@lang("invoice::invoices.show.total")</th>
            </tr>
            </thead>

            <tbody>
            @foreach ($invoice->lines as $index => $line)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>
                        {{ $line->description }}<br>
                        <small>{{ $line->sku }}</small>
                    </td>
                    <td>{{ $line->unit_price->format() }}</td>
                    <td>{{ $line->quantity }}</td>
                    <td>{{ $line->line_total_excl_tax->format() }}</td>
                    <td>{{ $line->tax_amount->format() }}</td>
                    <td>{{ $line->line_total_incl_tax->format() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <!-- SUMMARY SECTION -->
        <div class="summary-section">
            <!-- PAYMENTS -->
            <div class="summary-card" @if($invoice->allocations->count()==0)style="border: none" @endif>
                @if($invoice->allocations->count()>0)

                    <div class="summary-title">@lang("invoice::invoices.show.payment_summary")</div>

                    @foreach($invoice->allocations as $allocation)
                        <div class="summary-row">
                        <span>
                            {{ $allocation->payment->method->trans() }}
                            @if($allocation->payment->type->isRefund())
                                <span style="color:#EF4444;">({{ $allocation->payment->type->trans() }})</span>
                            @endif

                            @if($allocation->payment->transaction_id)
                                <div style="font-size:12px; font-weight:600;">
                                    @lang("invoice::invoices.show.transaction_id"):
                                    <span style="color:#4d4d4d; font-weight:normal;">
                                        {{ $allocation->payment->transaction_id }}
                                    </span>
                                </div>
                            @endif
                        </span>

                            <strong @if($allocation->payment->type->isRefund()) style="color:#EF4444" @endif>
                                {{ $allocation->amount->format() }}
                            </strong>
                        </div>
                    @endforeach
                @endif
            </div>


            <!-- TOTALS -->
            <div class="summary-card">

                <div class="summary-row">
                    <span>@lang("invoice::invoices.show.sub_total")</span>
                    <strong>{{ $invoice->subtotal->format() }}</strong>
                </div>

                @foreach ($invoice->discounts as $discount)
                    <div class="summary-row">
                        <span>
                            @lang("invoice::invoices.show.discount")
                            <span style="text-decoration: underline">({{ $discount->name }})</span>
                        </span>
                        <strong class="text-green">{{ $discount->amount->format() }}</strong>
                    </div>
                @endforeach

                @foreach ($invoice->taxes as $tax)
                    <div class="summary-row">
                        <span>{{ $tax->name }}</span>
                        <strong>{{ $tax->amount->format() }}</strong>
                    </div>
                @endforeach

                <div
                    class="summary-row border-t-dashed @if(!$invoice->invoice_kind->isCreditNote()) border-b-dashed @endif summary-total-primary">
                    <span>@lang("invoice::invoices.show.total")</span>
                    <strong>{{ $invoice->total->format() }}</strong>
                </div>
                @if(!$invoice->invoice_kind->isCreditNote())
                    <div class="summary-row">
                        <span>@lang("invoice::invoices.show.paid_amount")</span>
                        <strong>{{ $invoice->paid_amount->format() }}</strong>
                    </div>

                    <div class="summary-row">
                        <span>@lang("invoice::invoices.show.refunded_amount")</span>
                        <strong>{{ $invoice->refunded_amount->format() }}</strong>
                    </div>

                    <div class="summary-row summary-total border-t-dashed">
                        <span>@lang("invoice::invoices.show.net_paid")</span>
                        <strong>{{ $invoice->net_paid->format() }}</strong>
                    </div>
                @endif
            </div>
        </div>

        <!-- UUID -->
        <div class="uuid-text">
            <span class="uuid-title">@lang("invoice::invoices.show.uuid")</span>: {{ $invoice->uuid }}
        </div>

    </div>
</div>

</body>
</html>
