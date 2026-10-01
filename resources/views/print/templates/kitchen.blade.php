@extends('print.layout')

@php
    $isNarrow = (int)($profile['paper_width_mm'] ?? 80) <= 58;

    $order = (array)data_get($payload, 'order', []);
    $waiter = data_get($payload, 'waiter');
    $table = data_get($payload, 'table');

    $productsPayload = data_get($payload, 'products', []);

    $kitchen = (array)data_get(
        $payload,
        'kitchen',
        (is_array($productsPayload) ? data_get($productsPayload, 'kitchen', []) : [])
    );

    if (is_array($productsPayload) && array_key_exists('products', $productsPayload)) {
        $products = collect(data_get($productsPayload, 'products', []));
    } else {
        $products = collect($productsPayload);
    }

@endphp

@section('title', 'Kitchen Ticket')

@section('header')
<div class="doc-head-title"></div>
    <div class="center">
        <div class="title" style="margin-bottom: 1mm;">Kitchen Ticket</div>
    </div>
    <div class="doc-card">        
        <div class="doc-head">
            <div class="doc-head-top">
                @if(data_get($order, 'type'))
                    <span class="badge">{{ data_get($order, 'type') }}</span>
                @endif
                @if(!empty($table))
                    <span class="badge">Table: {{ data_get($table, 'name') }}</span>
                @endif
            </div>

            <div class="doc-id">
                #{{ data_get($order, 'order_number') ?: (data_get($order, 'reference_no') ?: '—') }}
            </div>

            <div class="doc-subline">
                @if(!empty($waiter))
                    <span><strong>Waiter:</strong> {{ data_get($waiter, 'name') }}</span>
                @endif
                @if(data_get($order, 'scheduled_at'))
                    <span><strong>Scheduled:</strong> {{ data_get($order, 'scheduled_at') }}</span>
                @elseif(data_get($order, 'order_date'))
                    <span><strong>Date:</strong> {{ data_get($order, 'order_date') }}</span>
                @endif
            </div>
        </div>

        @if(data_get($order, 'car_plate') || data_get($order, 'car_description'))
            <div class="muted small" style="margin-top: 1.5mm;">
                <strong style="color: var(--ink);">Vehicle:</strong>
                {{ data_get($order, 'car_plate') }}
                @if(data_get($order, 'car_description')) · {{ data_get($order, 'car_description') }} @endif
            </div>
        @endif

        @if(data_get($order, 'notes'))
            <hr class="hr" style="margin: 2mm 0;">
            <div class="section-title" style="margin-bottom: 1mm;">Notes</div>
            <div class="notes" style="font-size: 1.05em; border-style: solid; border-color: rgba(0,0,0,.35);">
                {{ data_get($order, 'notes') }}
            </div>
        @endif
    </div>

    <hr class="hr">
@endsection

@section('content')
    <div class="section-title">{{ data_get($payload, 'section_title', 'Items') }}</div>
    @if($products->isEmpty())
        <div class="muted small">No items.</div>
    @else
        <div class="items">
            @php
                // Group by course so coursed tickets print Starter → Main → … in order.
                $orderedProducts = collect($products)
                    ->sortBy(fn($row) => (int) (data_get((array) $row, 'course_number') ?? 0))
                    ->values();
                $lastCourse = null;
            @endphp
            @foreach($orderedProducts as $p)
                @php
                    $p = (array)$p;
                    $options = collect(data_get($p, 'options', []));
                    $qty = (int)data_get($p, 'quantity', 0);
                    $seat = data_get($p, 'seat_number');
                    $course = data_get($p, 'course_number');
                @endphp
                @if($course && $course !== $lastCourse)
                    <div class="section-title" style="margin-top: 6px;">Course {{ $course }}</div>
                    @php $lastCourse = $course; @endphp
                @endif
                <div class="k-item">
                    <div class="k-item-main">
                        <div style="flex: 0 0 auto;">
                            <div class="qty-box" style="font-size: {{ $isNarrow ? '1.05em' : '1.15em' }};">×{{ $qty }}</div>
                        </div>
                        <div style="flex: 1 1 auto;">
                            <div class="k-item-name">@if($seat)Seat {{ $seat }} · @endif{{ data_get($p, 'name') }}</div>
                            @if($options->isNotEmpty())
                                <div class="k-item-meta">
                                    @foreach($options as $opt)
                                        @php
                                            $opt = (array)$opt;
                                            $labels = collect(data_get($opt, 'values', []))->pluck('label')->filter()->implode(', ');
                                        @endphp
                                        <div class="k-mod">• {{ data_get($opt, 'name') }}@if(!empty($labels)): {{ $labels }}@endif</div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection

@section('footer')
    <hr class="hr">
    <div class="center muted small">
        Kitchen copy · Order #{{ data_get($order, 'order_number') ?: (data_get($order, 'reference_no') ?: '—') }}
    </div>
@endsection
