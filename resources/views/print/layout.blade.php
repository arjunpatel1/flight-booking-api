<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    @php
        $paperSizeValue = isset($paperSize) ? $paperSize->value : '80mm';
        $fallbackWidthMm = (int)str_replace('mm', '', (string)$paperSizeValue) ?: 80;
        $paperWidthMm = (int)($profile['paper_width_mm'] ?? $fallbackWidthMm);
        $paperPixelWidth = (int)($profile['pixel_width'] ?? 576);
        $isNarrow = $paperWidthMm <= 58;
        $fontSize = $isNarrow ? 11 : 12;
        $paddingMm = $isNarrow ? 2.5 : 3.5;
        $safeCutMarginMm = max(0, (float) config('printer.browser.safe_cut_margin_mm', 8));

        $toFontData = function (?string $path): ?string {
            if (empty($path) || !is_readable($path)) {
                return null;
            }
            return base64_encode(file_get_contents($path));
        };

        $cairoRegularPath = config('printer.fonts.cairo.regular');
        $cairoBoldPath =  config('printer.fonts.cairo.bold');
        $embedFonts = (bool) config('printer.browser.embed_fonts', false);

        $cairoRegularData = $embedFonts ? $toFontData($cairoRegularPath) : null;
        $cairoBoldData = $embedFonts ? $toFontData($cairoBoldPath) : null;

    @endphp
    <style>
        @if(!empty($cairoRegularData))
        @font-face {
            font-family: "Cairo";
            src: url("data:font/ttf;base64,{{ $cairoRegularData }}") format("truetype");
            font-weight: 400;
            font-style: normal;
        }
        @endif

        @if(!empty($cairoBoldData))
        @font-face {
            font-family: "Cairo";
            src: url("data:font/ttf;base64,{{ $cairoBoldData }}") format("truetype");
            font-weight: 600;
            font-style: normal;
        }
        @endif

        @if(!empty($cairoBoldData))
        @font-face {
            font-family: "Cairo";
            src: url("data:font/ttf;base64,{{ $cairoBoldData }}") format("truetype");
            font-weight: 700;
            font-style: normal;
        }
        @endif

        :root {
            --paper-width-mm: {{ $paperWidthMm }};
            --paper-width-px: {{ $paperPixelWidth }};
            --pad-mm: {{ $paddingMm }};
            --safe-cut-margin-mm: {{ $safeCutMarginMm }};
            --font-size: {{ $fontSize }}px;
            --doc-number-scale: {{ $isNarrow ? 1.55 : 1.85 }};
            --muted: #000000;
            --ink: #000000;
            --line: rgba(0, 0, 0, .85);
        }

        @page {
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        html, body {
            padding: 0;
            margin: 0;
        }

        body {
            background: #fff;
            color: var(--ink);
            font-family: "Cairo", Arial, Helvetica, sans-serif;
            font-size: var(--font-size);
            line-height: 1.25;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            text-rendering: optimizeLegibility;
            font-weight: 500;
        }

        .paper {
            width: calc(var(--paper-width-mm) * 1mm);
            max-width: calc(var(--paper-width-px) * 1px);
            padding: calc(var(--pad-mm) * 1mm);
            padding-bottom: calc((var(--pad-mm) + var(--safe-cut-margin-mm)) * 1mm);
            margin: 0 auto;
        }

        .center {
            text-align: center;
        }

        .right {
            text-align: right;
        }

        .muted {
            color: var(--muted);
        }

        .bold {
            font-weight: 700;
        }

        .small {
            font-size: .92em;
        }

        .xsmall {
            font-size: .85em;
        }

        .title {
            font-size: 1.15em;
            font-weight: 800;
            letter-spacing: .6px;
            text-transform: uppercase;
        }

        .logo {
            display: block;
            margin: 0 auto 2mm;
            max-width: 18mm;
            max-height: 18mm;
            object-fit: contain;
            image-rendering: -webkit-optimize-contrast;
            image-rendering: crisp-edges;
            image-rendering: pixelated;
            filter: contrast(1.2) brightness(1.0);
        }

        .brand {
            text-align: center;
        }

        .brand-name {
            font-weight: 800;
            letter-spacing: .2px;
        }

        .brand-sub {
            color: var(--muted);
            font-size: .9em;
            line-height: 1.25;
            margin-top: .4mm;
        }

        .hr {
            border: 0;
            border-top: 1px dashed rgba(0, 0, 0, .35);
            margin: 2.5mm 0;
        }

        .kv {
            display: flex;
            justify-content: space-between;
            gap: 2mm;
        }

        .kv .k {
            color: var(--muted);
        }

        .kv .v {
            text-align: right;
            white-space: nowrap;
            font-weight: 600;
        }

        .section-title {
            font-size: .95em;
            font-weight: 800;
            letter-spacing: .3px;
            text-transform: uppercase;
            margin: 0 0 1.5mm;
        }

        .items {
            display: flex;
            flex-direction: column;
            /* gap: 2mm; */
        }

        .item {
            padding: 1.5mm 0;
            border-bottom: 1px solid var(--line);
        }

        .item:last-child {
            border-bottom: 0;
        }

        .item-top {
            display: flex;
            justify-content: space-between;
            gap: 2mm;
        }

        .item-name {
            flex: 1 1 auto;
            font-weight: 700;
            word-break: break-word;
        }

        .item-meta {
            flex: 0 0 auto;
            text-align: right;
            white-space: nowrap;
            font-weight: 700;
        }

        .qty-box {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 10mm;
            padding: .8mm 1.2mm;
            border: 1px solid rgba(0, 0, 0, .25);
            border-radius: 1.6mm;
            font-weight: 900;
            letter-spacing: .2px;
        }

        .k-item {
            padding: 2mm 0;
            border-bottom: 1px solid var(--line);
        }

        .k-item:last-child {
            border-bottom: 0;
        }

        .k-item-main {
            display: flex;
            gap: 2mm;
            align-items: flex-start;
        }

        .k-item-name {
            font-weight: 900;
            font-size: 1.15em;
            line-height: 1.15;
            word-break: break-word;
        }

        .k-item-meta {
            margin-top: .7mm;
            color: var(--muted);
            font-size: .9em;
        }

        .k-mod {
            padding-left: 3mm;
            color: var(--ink);
            font-size: .95em;
        }

        .item-sub {
            margin-top: 1mm;
            display: flex;
            flex-direction: column;
            gap: .7mm;
        }

        .item-option {
            padding-left: 3mm;
            color: var(--muted);
        }

        .totals {
            display: flex;
            flex-direction: column;
            gap: 1mm;
        }

        .badge {
            display: inline-block;
            padding: .8mm 1.6mm;
            border: 1px solid rgba(0, 0, 0, .25);
            border-radius: 1.2mm;
            font-size: .85em;
            font-weight: 800;
            letter-spacing: .2px;
        }

        .doc-header {
            text-align: center;
        }

        .doc-title-row {
            display: flex;
            gap: 2mm;
            align-items: baseline;
            justify-content: center;
        }

        .doc-number {
            margin-top: 1mm;
            padding: 1mm 0;
            font-size: calc(var(--font-size) * var(--doc-number-scale));
            font-weight: 800;
            letter-spacing: .8px;
            border-top: 1px solid rgba(0, 0, 0, .22);
            border-bottom: 1px solid rgba(0, 0, 0, .22);
        }

        .doc-meta {
            margin-top: 1.2mm;
            font-size: .9em;
            color: var(--muted);
            display: flex;
            gap: 0;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
        }

        .doc-meta span {
            white-space: nowrap;
        }

        .doc-meta span + span::before {
            content: " · ";
            color: rgba(0, 0, 0, .35);
        }

        .doc-meta strong {
            color: var(--ink);
            font-weight: 700;
        }

        .doc-card {
            border: 1px solid rgba(0, 0, 0, .18);
            border-radius: 2mm;
            padding: 2mm;
        }

        .doc-head {
            text-align: center;
        }

        .doc-head-top {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1.8mm;
            flex-wrap: wrap;
        }

        .doc-head-title {
            font-size: 1.25em;
            font-weight: 900;
            letter-spacing: .6px;
            text-transform: uppercase;
        }

        .doc-id {
            margin-top: 1.5mm;
            font-size: calc(var(--font-size) * var(--doc-number-scale));
            font-weight: 900;
            letter-spacing: 1px;
        }

        .doc-subline {
            margin-top: .8mm;
            font-size: .9em;
            color: var(--muted);
            display: flex;
            justify-content: center;
            gap: 0;
            flex-wrap: wrap;
        }

        .doc-subline span + span::before {
            content: " · ";
            color: rgba(0, 0, 0, .35);
        }

        .doc-info {
            margin-top: 1.8mm;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 1.5mm 2mm;
            text-align: left;
        }

        .doc-info .info {
            flex: 1 1 44%;
            min-width: 40mm;
        }

        .doc-info .label {
            font-size: .85em;
            color: var(--muted);
        }

        .doc-info .value {
            font-weight: 700;
            word-break: break-word;
        }

        @media (max-width: 420px) {
            .doc-info .info {
                min-width: 100%;
            }
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2mm;
        }

        .box {
            border: 1px solid rgba(0, 0, 0, .15);
            border-radius: 2mm;
            padding: 2mm;
        }

        .qrcode {
            display: block;
            margin: 2mm auto 0;
            width: 22mm;
            height: 22mm;
        }

        .notes {
            border: 1px dashed rgba(0, 0, 0, .25);
            border-radius: 2mm;
            padding: 2mm;
            white-space: pre-wrap;
            word-break: break-word;
        }

        @media print {
            :root {
                --muted: #000000;
                --ink: #000000;
                --line: #000000;
            }

            html, body, .paper {
                background: #ffffff !important;
                color: #000000 !important;
            }

            body {
                font-weight: 600;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .muted,
            .brand-sub,
            .kv .k,
            .item-option,
            .k-item-meta,
            .doc-meta,
            .doc-subline,
            .doc-info .label {
                color: #000000 !important;
            }

            .hr,
            .line,
            .item,
            .k-item,
            .qty-box,
            .badge,
            .doc-card,
            .box,
            .notes,
            .items-table th,
            .items-table td,
            .total-bar {
                border-color: #000000 !important;
            }

            .hr,
            .line {
                border-top-color: #000000 !important;
                border-bottom-color: #000000 !important;
            }

            .doc-meta span + span::before,
            .doc-subline span + span::before {
                color: #000000 !important;
            }
        }
    </style>
</head>
<body>
<div class="paper" data-paper-size="{{ $paperSizeValue }}">
    @yield('header')
    @yield('content')
    @yield('footer')
</div>
</body>
</html>
