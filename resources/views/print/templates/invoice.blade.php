@extends('print.layout')

@php
    $seller   = (array) data_get($payload,'seller',[]);
    $buyer    = (array) data_get($payload,'buyer',[]);
    $branch   = (array) data_get($payload,'branch',[]);
    $order    = (array) data_get($payload,'order',[]);
    $table    = (array) data_get($payload,'table',[]);
    $lines    = collect(data_get($payload,'lines',[]));
    $taxes    = collect(data_get($payload,'taxes',[]));
    $payments = collect(data_get($payload,'allocations',[]));

    $issuedAt = data_get($payload,'issued_at.full_date');
    $invoiceNo= data_get($payload,'invoice_number');

    $currencySubunit = (int) data_get($payload,'currency_subunit',2);
    $fmt = fn($n)=>number_format((float)$n,$currencySubunit,'.','');

    $subtotal = data_get($payload,'subtotal');
    $total    = data_get($payload,'total');
    $discounts = collect(data_get($payload,'discounts',[]));

    $totalQty   = $lines->sum('quantity');
    $totalItems = $lines->count();

    $logo = data_get($branch, 'logo');
    $qrcode = null;
    $printTerms = trim((string) setting('appearance_print_terms', ''));
    $printTerms = $printTerms !== '' ? $printTerms : 'No Exchange & No Return';
    $printFooterText = trim((string) setting('appearance_footer_text', ''));
    $printedOrderType = str(data_get($order, 'type', '-'))->replace(['-', '_'], ' ')->title()->toString();
    $printedTable = filled(data_get($table, 'name')) ? data_get($table, 'name') : '-';
@endphp

@section('title','Invoice')

{{-- ================= HEADER ================= --}}
@section('header')

<style>

.receipt-title{
    text-align:center;
    font-size:13px;
    font-weight:700;
}

.line{
    border-bottom:2px solid #000;
    margin:5px 0;
}

/* LOGO HEADER */
.logo-header{
    display:flex;
    justify-content:center;
    align-items:center;
    width:100%;
    position:relative;
    min-height:{{ !empty($logo) ? '18mm' : 'auto' }};
}

.logo-box{
    display:{{ !empty($logo) ? 'block' : 'none' }};
    position:absolute;
    left:0;
    top:50%;
    transform:translateY(-50%);
    max-width:18mm;
}

.logo-box img{
    max-width:18mm;
    max-height:18mm;
    object-fit:contain;
}

.name-box{
    width:100%;
    text-align:center;
}

.store-name{
    font-size:16px;
    font-weight:900;
    text-transform:uppercase;
}

.store-info{
    text-align:left;
    font-size:11px;
    font-weight:600;
}

.meta{
    display:flex;
    justify-content:space-between;
    font-size:11px;
    font-weight:700;
}

.center{text-align:center;}

</style>

<div class="receipt-title">INVOICE</div>

<div class="meta">
    <div>Invoice : #{{ $invoiceNo }}</div>
    <div>{{ $issuedAt }}</div>
</div>

<div class="meta">
    <div>{{ $printedOrderType }} : {{ $printedTable }}</div>
</div>

<div class="line"></div>

<div class="logo-header">
    @if(!empty($logo))
        <div class="logo-box">
            <img src="{{ $logo }}" alt="Logo">
        </div>
    @endif

    <div class="name-box">
        <div class="store-name">
            {{ data_get($branch,'name') ?? data_get($seller,'legal_name') }}
        </div>
    </div>

</div>

<div class="line"></div>

<div class="store-info">
    @if(data_get($branch, 'address_line1') || data_get($branch, 'address_line2'))
        <div class="brand-sub">
            {{ data_get($branch, 'address_line1') }}@if(data_get($branch, 'address_line2')), {{ data_get($branch, 'address_line2') }}@endif
        </div>
    @endif
    @if(data_get($branch, 'phone'))
      Phone :  {{ data_get($branch, 'phone') }}
    @endif
</div>

<div class="line"></div>

<div class="meta">
    <div>TIN : {{ data_get($seller,'vat_tin') }}</div>
    <div>CR : {{ data_get($seller,'cr_number') }}</div>
</div>

<div class="line"></div>

<div class="meta">
    <div>Name : {{ data_get($buyer,'legal_name') }}</div>
    <div>Phone : {{ data_get($buyer,'phone') }}</div>
</div>

<div class="line"></div>
@endsection



{{-- ================= CONTENT ================= --}}
@section('content')

<style>

.items-table{
    width:100%;
    border-collapse:collapse;
    font-size:11px;
}

.items-table th{
    border-bottom:2px solid #000;
    padding:3px 0;
}

.items-table td{
    padding:3px 0;
    text-align:center;
}

.items-table td:first-child{
    text-align:left;
}

.total-bar{
    display:flex;
    justify-content:space-between;
    border-top:1px solid #000;
    border-bottom:1px solid #000;
    padding:3px 0;
    font-weight:700;
}

.summary{
    text-align:right;
    font-size:12px;
    font-weight:600;
}

.tax-row{
    display:flex;
    justify-content:flex-end;
    font-size:12px;
}

.net{
    display:flex;
    justify-content:space-between;
    font-weight:900;
    font-size:18px;
}

</style>

<table class="items-table">
<thead>
<tr>
    <th width="5%">#</th>
    <th width="40%">Item</th>
    <th width="15%">Qty</th>
    <th width="20%">Price</th>
    <th width="20%">Amount</th>
</tr>
</thead>

<tbody>
@foreach($lines as $line)
<tr>
    <td>{{ $loop->iteration }}</td>
    <td>{{ data_get($line,'description') }}</td>
    <td>{{ data_get($line,'quantity') }}</td>
    <td>{{ $fmt(data_get($line,'unit_price')) }}</td>
    <td>{{ $fmt(data_get($line,'line_total_incl_tax')) }}</td>
</tr>
@endforeach
</tbody>
</table>

<div class="total-bar">
    <span>Total Item : ( {{ $totalItems }} )</span>
    <span>Total Qty : ( {{ $totalQty }} )</span>
</div>

<div class="summary">
    Bill Amount : {{ $fmt($subtotal) }}
</div>

{{-- Discounts --}}
@foreach($discounts as $d)
<div class="summary">
    Discount ({{ data_get($d,'name') }}) :
    - {{ $fmt(data_get($d,'amount')) }}
</div>
@endforeach

<div class="line"></div>

{{-- Taxes --}}
@foreach($taxes as $tax)
<div class="tax-row">
    {{ data_get($tax,'name') }} :
    {{ $fmt(data_get($tax,'amount')) }}
</div>
@endforeach

<div class="line"></div>

<div class="net">
    <span></span>
    <span>Net Payable : {{ $fmt($total) }}</span>
</div>

<div class="line"></div>

{{-- Payments --}}
@foreach($payments as $pay)
<div class="meta">
    <span>{{ data_get($pay,'payment.method') }}</span>
    <span>{{ $fmt(data_get($pay,'amount')) }}</span>
</div>
@endforeach

@endsection



{{-- ================= FOOTER ================= --}}
@section('footer')

<style>
.terms{font-weight:800;font-size:16px;}
.small{font-size:11px;}
</style>

<div class="line"></div>

<div class="terms-section">
    <div class="center small">
        <u>Terms & Conditions</u>
    </div>

    <div class="center terms">
        {!! nl2br(e($printTerms)) !!}
    </div>
</div>

@if($printFooterText !== '')
    <div class="line"></div>
    <div class="center small">{{ $printFooterText }}</div>
@endif
      @if(!empty($qrcode))
        <hr class="hr">
        <div class="center">
            <img class="qrcode" src="data:{{ data_get($payload, 'qrcode_mime_type', 'image/png') }};base64,{{ $qrcode }}" alt="QR Code">
            @if(data_get($payload, 'uuid'))
                <div class="muted xsmall" style="margin-top: 1mm;">{{ data_get($payload, 'uuid') }}</div>
            @endif
        </div>
    @endif
@endsection
