@extends('print.layout')

@php
    $order = (array) data_get($payload, 'order', []);
    $branch = (array) data_get($payload, 'branch', []);
    $customer = (array) data_get($payload, 'customer', []);
    $table = (array) data_get($payload, 'table', []);
    $products = collect(data_get($payload, 'products', []));
    $taxes = collect(data_get($payload, 'taxes', []));
    $payments = collect(data_get($payload, 'payments', []));
    $discount = data_get($payload, 'discount');

    $currencySubunit = (int) data_get($payload, 'currency_subunit', 2);
    $fmt = fn($n) => number_format((float)($n ?? 0), $currencySubunit, '.', '');

    $subTotal = (float) data_get($order,'subtotal',0);
    $totalQty = $products->sum('quantity');
    $totalItems = $products->count();

    $logo = data_get($branch, 'logo');
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

/* ===== Screenshot Receipt CSS ===== */

.receipt-title{
    text-align:center;
    font-size:13px;
    font-weight:700;
}

.line{
    border-bottom:2px solid #000;
    margin:5px 0;
    border-color: #000000;
}

/* ===== LOGO + NAME 30 / 70 CENTER ===== */

.logo-header{
    display:flex;
    justify-content:center;   /* pura block center */
    align-items:center;
    width:100%;
    position:relative;
    min-height:{{ !empty($logo) ? '18mm' : 'auto' }};
    margin-bottom:5px;
}

.logo-header .logo-box{
    display:{{ !empty($logo) ? 'block' : 'none' }};
    position:absolute;
    left:0;
    top:50%;
    transform:translateY(-50%);
    max-width:18mm;
}

.logo-header .logo-box img{
    max-width:18mm;
    max-height:18mm;
    object-fit:contain;
    image-rendering: -webkit-optimize-contrast;
    image-rendering: crisp-edges;
    filter: contrast(1.3) brightness(1.1);
    -webkit-font-smoothing: antialiased;
}

.logo-header .name-box{
    width:100%;
    text-align:center;
}

.store-name{
    font-size:18px;
    font-weight:900;
    text-transform:uppercase;
    line-height:1.2;
    color: #000000;
    text-rendering: optimizeLegibility;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* ===== ADDRESS LEFT ===== */

.store-info{
    width:100%;
    text-align:left;   /* LEFT as asked */
    font-size:11px;
    font-weight:700;
    line-height:1.3;
}

.meta{
    display:flex;
    justify-content:space-between;
    font-size:11px;
    font-weight:700;
}

</style>

<div class="receipt-title">INVOICE</div>
<div class="meta">
    <div>Invoice : #{{ data_get($order,'order_number') }}</div>
    <div>{{ data_get($order,'order_date') }}</div>
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
            {{ data_get($branch,'name') }}
        </div>
    </div>

</div>

<div class="line"></div>

<div class="store-info">
    {{ data_get($branch,'address_line1') }}@if(data_get($branch,'address_line2')), {{ data_get($branch,'address_line2') }}@endif<br>
    Phone : {{ data_get($branch,'phone') }}
</div>

<div class="line"></div>

<div class="meta">
    <div>GST : {{ data_get($branch,'tax_number') }}</div>
    <div>Order ID : {{ data_get($order,'order_number') }}</div>
</div>

<div class="line"></div>

<div class="meta">
    <div>Name : {{ data_get($customer,'name') }}</div>
    <div>Phone : {{ data_get($customer,'phone') }}</div>
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
    border-color: #000000;
    color: #000000;
    font-weight: 800;
}

.items-table td{
    padding:3px 0;
    text-align:center;
    color: #000000;
    font-weight: 600;
}

.items-table td:first-child{
    text-align:left;
}

.total-bar{
    display:flex;
    justify-content:space-between;
    border-top:2px solid #000;
    border-bottom:2px solid #000;
    padding:3px 0;
    font-weight:700;
    border-color: #000000;
    color: #000000;
}

.summary{
    text-align:right;
    font-size:12px;
    font-weight:600;
    color: #000000;
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
    color: #000000;
    text-rendering: optimizeLegibility;
    -webkit-font-smoothing: antialiased;
}

.center{text-align:center;}

</style>

<table class="items-table">
    <thead>
<tr>
    <th width="5%">#</th>
    <th width="30%">Item Name</th>
    <th width="12%">Qty</th>
    <th width="17%">MRP</th>
    <th width="18%">AMT</th>
</tr>
</thead>
    <tbody>
        @foreach($products as $p)
            @php
                $qty = (float) data_get($p,'quantity');
                $price = (float) data_get($p,'unit_price');
                $total = (float) data_get($p,'total');
                $seat = data_get($p,'seat_number');
            @endphp
            <tr>
                <td>{{ $loop->iteration }}.</td>
                <td>@if($seat)S{{ $seat }} · @endif{{ data_get($p,'name') }}</td>
                <td>{{ $qty }}</td>
                <td>{{ $fmt($price) }}</td>
                <td>{{ $fmt($total) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="total-bar">
    <span>Total Item : ( {{ $totalItems }} )</span>
    <span>Total Qty : ( {{ $totalQty }} )</span>
</div>

<div class="summary">
    Bill Amount : {{ $fmt($subTotal) }}
</div>
@if(!empty($discount))
    <div class="summary"> Discount ({{ data_get($discount, 'name') }}) - {{ $fmt(data_get($discount, 'amount')) }}</div>
@endif
@if((float) data_get($order, 'customer_delivery_fee', 0) > 0)
    <div class="summary">Delivery Fee : {{ $fmt(data_get($order, 'customer_delivery_fee')) }}</div>
@endif

<div class="line"></div>

@foreach($taxes as $tax)
    <div class="tax-row">{{ data_get($tax, 'name') }} : {{ $fmt(data_get($tax, 'amount')) }}</div>
@endforeach

<div class="line"></div>
@if(!is_null(data_get($order, 'total')))
<div class="net">
    <span></span>
    <span>Net Payable : {{ $fmt(data_get($order, 'total')) }}</span>
</div>
@endif


@if(!empty($discount))
    <div class="line"></div>
    <div class="center" style="font-weight:700;">
        Total Amount Save On This Bills : {{ $fmt(data_get($discount, 'amount')) }}/-
    </div>
@endif

@foreach($payments as $pay)
<div class="meta">
    <span>{{ data_get($pay,'method') }}</span>
    <span>{{ $fmt(data_get($pay,'amount')) }}</span>
</div>
@endforeach

@endsection

@section('footer')
<style>
.terms{font-weight:800;font-size:16px;}
</style>

<div class="line"></div>
<div class="center small">
    <u>Terms & conditions</u>
</div>

<div class="center terms">
    {!! nl2br(e($printTerms)) !!}
</div>

@if($printFooterText !== '')
    <div class="line"></div>
    <div class="center small">{{ $printFooterText }}</div>
@endif

@endsection
