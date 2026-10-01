@extends('print.layout')

@php
    $order = (array)data_get($payload, 'order', []);
    $customer = (array)data_get($payload, 'customer', []);
    $table = data_get($payload, 'table');
    $waiter = data_get($payload, 'waiter');
    $products = collect(data_get($payload, 'products', []));
@endphp

@section('title', 'Waiter Copy')


{{-- ================= HEADER ================= --}}
@section('header')

<style>

/* ===== HEADER ===== */

.waiter-title{
    text-align:center;
    font-size:14px;
    font-weight:900;
    text-transform:uppercase;
}

.meta{
    display:flex;
    justify-content:space-between;
    font-size:11px;
    font-weight:700;
}

.line{
    border-bottom:2px solid #000;
    margin:5px 0;
}

/* ===== ITEMS LIST DESIGN ===== */

.items{
    display:flex;
    flex-direction:column;
    gap:6px;
}

.item{
    border-bottom:2px solid #999;
    padding-bottom:4px;
}

.item-top{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
}

.item-name{
    font-weight:700;
    font-size:12px;
    width:78%;
    word-break:break-word;
}

.item-qty{
    text-align:right;
    font-weight:700;
    width:22%;
    white-space:nowrap;
}

/* OPTIONS / NOTES */

.item-options{
    font-size:10px;
    margin-top:2px;
    padding-left:10px;
}

.item-options div{
    line-height:1.3;
}


</style>



<div class="waiter-title">WAITER COPY</div>

<div class="meta">
    <div>
        Order # :
        {{ data_get($order,'order_number') ?: data_get($order,'reference_no') }}
    </div>
    <div>
        {{ data_get($order,'type') }}
    </div>
</div>

@if(!empty($table))
<div class="meta">
    <div>Table : {{ data_get($table,'name') }}</div>
</div>
@endif

@if(!empty($waiter))
<div class="meta">
    <div>Waiter : {{ data_get($waiter,'name') }}</div>
</div>
@endif

@if(data_get($customer,'name'))
<div class="meta">
    <div>Customer : {{ data_get($customer,'name') }}</div>
</div>
@endif

<div class="line"></div>

@endsection




{{-- ================= CONTENT ================= --}}
@section('content')

<div class="items">

@foreach($products as $p)

@php
    $p = (array)$p;

    $qty  = (float)data_get($p,'quantity',0);
    $seat = data_get($p,'seat_number');

    $options = collect(data_get($p,'options',[]));
@endphp


<div class="item">

    {{-- TOP ROW --}}
    <div class="item-top">

        <div class="item-name">
            @if($seat)Seat {{ $seat }} · @endif{{ data_get($p,'name') }}
        </div>

        <div class="item-qty">
            × {{ rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') }}
        </div>

    </div>
    {{-- OPTIONS --}}
    @if($options->isNotEmpty())

    <div class="item-options">

        @foreach($options as $opt)

            @php
                $values = collect(data_get($opt,'values',[]));
                $labels = $values->pluck('label')->filter()->implode(', ');
            @endphp

            <div>
                + {{ data_get($opt,'name') }} : {{ $labels }}
            </div>

        @endforeach

    </div>

    @endif

</div>

@endforeach

</div>


<div class="line"></div>

@endsection




{{-- ================= FOOTER ================= --}}
@section('footer')

@if(data_get($order,'notes'))
<div class="line"></div>

<div style="font-size:11px;font-weight:700;">
    Notes
</div>

<div style="font-size:11px;">
    {{ data_get($order,'notes') }}
</div>
@endif

@endsection
