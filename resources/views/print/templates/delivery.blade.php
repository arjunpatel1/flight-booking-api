@extends('print.layout')

@php
    $order = (array)data_get($payload, 'order', []);
    $branch = (array)data_get($payload, 'branch', []);
    $customer = (array)data_get($payload, 'customer', []);
    $discount = data_get($payload, 'discount');
    $products = collect(data_get($payload, 'products', []));
    $taxes = collect(data_get($payload, 'taxes', []));
    $payments = collect(data_get($payload, 'payments', []));

    $currencySubunit = (int)data_get($payload, 'currency_subunit', 2);
    $fmt = fn($n) => number_format((float)($n ?? 0), $currencySubunit, '.', '');
    $logo = data_get($branch, 'logo');
@endphp

@section('title', 'Delivery')

@section('header')
    @if(!empty($logo))
        <img class="logo" src="{{ $logo }}" alt="Logo">
    @endif
    <div class="center">
        <div class="bold">{{ data_get($branch, 'name') ?: data_get($branch, 'legal_name') }}</div>
        <div class="title" style="margin-top: 1mm;">Delivery</div>
    </div>
    <hr class="hr">

    <div class="box">
        <div class="section-title" style="margin-bottom: 1mm;">Customer</div>
        <div class="bold">{{ data_get($customer, 'name') ?: '—' }}</div>
        <div class="muted small">
            @if(data_get($customer, 'phone'))
                {{ data_get($customer, 'phone') }}
            @endif
            @if(data_get($customer, 'email'))
                · {{ data_get($customer, 'email') }}
            @endif
        </div>
        @if(data_get($order, 'scheduled_at'))
            <div class="kv small" style="margin-top: 1mm;">
                <div class="k">Scheduled</div>
                <div class="v">{{ data_get($order, 'scheduled_at') }}</div>
            </div>
        @endif
    </div>

    <hr class="hr">
    <div class="kv">
        <div class="k">Order #</div>
        <div class="v">{{ data_get($order, 'order_number') ?: data_get($order, 'reference_no') }}</div>
    </div>
    <div class="kv">
        <div class="k">Type</div>
        <div class="v">{{ data_get($order, 'type') }}</div>
    </div>
@endsection

@section('content')
    @if(data_get($order, 'car_plate') || data_get($order, 'car_description'))
        <div class="section-title">Vehicle</div>
        <div class="muted small">
            @if(data_get($order, 'car_plate'))
                Plate: {{ data_get($order, 'car_plate') }}
            @endif
            @if(data_get($order, 'car_description'))
                · {{ data_get($order, 'car_description') }}
            @endif
        </div>
        <hr class="hr">
    @endif

    <div class="section-title">Items</div>
    <div class="items">
        @foreach($products as $p)
            @php
                $p = (array)$p;
                $options = collect(data_get($p, 'options', []));
            @endphp
            <div class="item">
                <div class="item-top">
                    <div class="item-name">{{ data_get($p, 'name') }}</div>
                    <div class="item-meta">
                        {{ (int)data_get($p, 'quantity', 0) }}
                        @if(!is_null(data_get($p, 'unit_price')))
                            <span class="muted">×</span> {{ $fmt(data_get($p, 'unit_price')) }}
                        @endif
                    </div>
                </div>
                @if(!is_null(data_get($p, 'total')))
                    <div class="kv xsmall" style="margin-top: 1mm;">
                        <div class="k">Line total</div>
                        <div class="v">{{ $fmt(data_get($p, 'total')) }}</div>
                    </div>
                @endif

                @if($options->isNotEmpty())
                    <div class="item-sub xsmall">
                        @foreach($options as $opt)
                            @php
                                $opt = (array)$opt;
                                $values = collect(data_get($opt, 'values', []));
                                $labels = $values->pluck('label')->filter()->implode(', ');
                                $optPrice = (float)$values->sum('price');
                            @endphp
                            <div class="item-option">
                                + {{ data_get($opt, 'name') }}: {{ $labels }}
                                @if($optPrice > 0)
                                    <span class="muted">({{ $fmt($optPrice) }})</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <hr class="hr">
    <div class="section-title">Summary</div>
    <div class="box">
        <div class="totals">
            @if(!is_null(data_get($order, 'subtotal')))
                <div class="kv">
                    <div class="k">Subtotal</div>
                    <div class="v">{{ $fmt(data_get($order, 'subtotal')) }}</div>
                </div>
            @endif
            @if(!empty($discount))
                <div class="kv">
                    <div class="k">Discount ({{ data_get($discount, 'discount') }})</div>
                    <div class="v">-{{ $fmt(data_get($discount, 'amount')) }}</div>
                </div>
            @endif
            @foreach($taxes as $tax)
                <div class="kv">
                    <div class="k">{{ data_get($tax, 'name') }}</div>
                    <div class="v">{{ $fmt(data_get($tax, 'amount')) }}</div>
                </div>
            @endforeach
            <hr class="hr" style="margin: 2mm 0;">
            @if(!is_null(data_get($order, 'total')))
                <div class="kv">
                    <div class="k bold">Total</div>
                    <div class="v bold">{{ $fmt(data_get($order, 'total')) }}</div>
                </div>
            @endif
            @if(!is_null(data_get($order, 'due_amount')) && (float)data_get($order, 'due_amount') > 0)
                <div class="kv">
                    <div class="k bold">Due</div>
                    <div class="v bold">{{ $fmt(data_get($order, 'due_amount')) }}</div>
                </div>
            @endif
        </div>
    </div>
@endsection

@section('footer')
    @if(data_get($order, 'notes'))
        <hr class="hr">
        <div class="section-title">Notes</div>
        <div class="notes small">{{ data_get($order, 'notes') }}</div>
    @endif
@endsection
