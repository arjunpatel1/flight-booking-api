<?php

namespace Modules\Printer\Services\Render;

use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Enum\PrinterPaperSize;

trait RendersFastEscPosText
{
    private function usesFastEscPosText(PrintContentType $type): bool
    {
        return in_array($type, [
            PrintContentType::Kitchen,
            PrintContentType::Waiter,
            PrintContentType::Bill,
            PrintContentType::Invoice,
        ], true) || in_array($type->value, (array) config('printer.escpos.fast_text_types', []), true);
    }

    private function renderFastTextEscPos(
        PrintContentType $type,
        array $payload,
        PrinterPaperSize $paperSize
    ): string {
        if (in_array($type, [PrintContentType::Bill, PrintContentType::Invoice, PrintContentType::Delivery], true)) {
            return $this->renderReceiptTextEscPos($type, $payload, $paperSize);
        }

        $width = $this->textColumns($paperSize, data_get($payload, '_print.text_columns'));
        $order = (array) data_get($payload, 'order', []);
        $customer = data_get($payload, 'customer');
        $waiter = data_get($payload, 'waiter');
        $table = data_get($payload, 'table');
        $products = $this->printProducts($payload);
        $title = $type === PrintContentType::Kitchen ? 'KOT' : 'WAITER COPY';
        $sectionTitle = (string) data_get($payload, 'section_title', 'Items');

        $lines = [];
        $lines[] = str_repeat('=', $width);

        $orderNo = data_get($order, 'order_number') ?: data_get($order, 'reference_no');
        if (filled($orderNo)) {
            $lines[] = $this->keyValueLine('Order', '#'.$orderNo, $width);
        }
        if (filled(data_get($order, 'type'))) {
            $lines[] = $this->keyValueLine('Type', data_get($order, 'type'), $width);
        }
        if (! empty($table)) {
            $lines[] = $this->keyValueLine('Table', data_get($table, 'name'), $width);
        }
        if (! empty($customer) && filled(data_get($customer, 'name'))) {
            $lines[] = $this->keyValueLine('Customer', data_get($customer, 'name'), $width);
        }
        if (! empty($waiter)) {
            $lines[] = $this->keyValueLine('Waiter', data_get($waiter, 'name'), $width);
        }
        if (filled(data_get($order, 'scheduled_at'))) {
            $lines[] = $this->keyValueLine('Time', data_get($order, 'scheduled_at'), $width);
        } elseif (filled(data_get($order, 'order_date'))) {
            $lines[] = $this->keyValueLine('Time', data_get($order, 'order_date'), $width);
        }
        if (filled(data_get($order, 'notes'))) {
            $lines[] = str_repeat('-', $width);
            $lines[] = 'NOTES';
            array_push($lines, ...$this->wrapText((string) data_get($order, 'notes'), $width));
        }

        $lines[] = str_repeat('=', $width);
        $lines[] = strtoupper($sectionTitle);
        $lines[] = str_repeat('-', $width);

        if ($products->isEmpty()) {
            $lines[] = 'No items.';
        } else {
            foreach ($products as $product) {
                $product = (array) $product;
                $qty = rtrim(rtrim(number_format((float) data_get($product, 'quantity', 0), 2, '.', ''), '0'), '.');
                $name = $this->cleanPrintText((string) data_get($product, 'name', 'Item'));
                $seat = data_get($product, 'seat_number');
                if (filled($seat)) {
                    $name = 'Seat '.$seat.' - '.$name;
                }
                $prefix = ($qty !== '' ? $qty : '0').' x ';
                foreach ($this->wrapText($prefix.$name, $width) as $productLine) {
                    $lines[] = $productLine;
                }

                foreach (collect(data_get($product, 'options', [])) as $option) {
                    $labels = collect(data_get($option, 'values', []))->pluck('label')->filter()->implode(', ');
                    $optionText = '+ '.data_get($option, 'name');
                    if (filled($labels)) {
                        $optionText .= ': '.$labels;
                    }
                    array_push($lines, ...$this->wrapText($optionText, $width));
                }
            }
        }

        $lines[] = str_repeat('=', $width);
        if ($type !== PrintContentType::Kitchen) {
            $lines[] = $this->centerText('WAITER COPY', $width);
        }

        return $this->fastTextEscPosPayload(
            $title,
            $lines,
            $type,
            '',
            $type === PrintContentType::Kitchen ? 'KITCHEN COPY' : ''
        );
    }

    private function renderReceiptTextEscPos(
        PrintContentType $type,
        array $payload,
        PrinterPaperSize $paperSize
    ): string {
        $width = $this->textColumns($paperSize, data_get($payload, '_print.text_columns'));
        $order = (array) data_get($payload, 'order', []);
        $branch = (array) data_get($payload, 'branch', []);
        $customer = (array) data_get($payload, 'customer', []);
        $table = data_get($payload, 'table');
        $currencySubunit = (int) data_get($payload, 'currency_subunit', 2);
        $title = $type === PrintContentType::Bill ? 'BILL' : 'INVOICE';
        $invoiceNo = data_get($payload, 'invoice_number') ?: data_get($order, 'order_number') ?: data_get($order, 'reference_no');
        $date = data_get($payload, 'issued_at.full_date') ?: data_get($order, 'order_date');
        $products = $type === PrintContentType::Invoice
            ? collect(data_get($payload, 'lines', []))->map(fn ($line) => [
                'name' => data_get($line, 'description'),
                'quantity' => data_get($line, 'quantity'),
                'unit_price' => data_get($line, 'unit_price'),
                'total' => data_get($line, 'line_total_incl_tax'),
            ])
            : $this->printProducts($payload);
        $taxes = collect(data_get($payload, 'taxes', []));
        $discounts = collect(data_get($payload, 'discounts', []));
        if ($discounts->isEmpty() && filled(data_get($payload, 'discount'))) {
            $discounts = collect([data_get($payload, 'discount')]);
        }
        $payments = $type === PrintContentType::Invoice
            ? collect(data_get($payload, 'allocations', []))->map(fn ($payment) => [
                'method' => data_get($payment, 'payment.method'),
                'amount' => data_get($payment, 'amount'),
            ])
            : collect(data_get($payload, 'payments', []));
        $subtotal = data_get($payload, 'subtotal', data_get($order, 'subtotal', 0));
        $total = data_get($payload, 'total', data_get($order, 'total', 0));
        $totalQty = $products->sum(fn ($product) => (float) data_get($product, 'quantity', 0));
        $logo = data_get($branch, 'logo') ?: data_get($payload, 'logo');

        $lines = [];

        if (filled($invoiceNo) || filled($date)) {
            $lines[] = $this->twoColumnLine(
                filled($invoiceNo) ? '# '.$invoiceNo : '',
                (string) $date,
                $width
            );
        }

        $orderReference = data_get($order, 'reference_no');
        if ($type === PrintContentType::Invoice && filled($orderReference)) {
            $lines[] = 'Order: '.$orderReference;
        }

        $orderType = data_get($order, 'type');
        $tableName = data_get($table, 'name');
        if (filled($orderType) || filled($tableName)) {
            $lines[] = $this->twoColumnLine((string) $orderType, filled($tableName) ? 'Table: '.$tableName : '', $width);
        }

        $lines[] = str_repeat('=', $width);
        $branchName = data_get($branch, 'name') ?: data_get($branch, 'legal_name') ?: data_get($payload, 'seller.legal_name');
        if (filled($branchName)) {
            $lines[] = $this->centerText((string) $branchName, $width);
        }
        foreach ([
            data_get($branch, 'address_line1'),
            data_get($branch, 'address_line2'),
            filled(data_get($branch, 'phone')) ? 'Phone: '.data_get($branch, 'phone') : null,
            filled(data_get($branch, 'tax_number')) ? 'GST: '.data_get($branch, 'tax_number') : null,
        ] as $branchLine) {
            if (filled($branchLine)) {
                array_push($lines, ...$this->wrapText((string) $branchLine, $width));
            }
        }

        $lines[] = str_repeat('-', $width);
        $customerName = data_get($customer, 'name') ?: data_get($payload, 'buyer.legal_name');
        $customerPhone = data_get($customer, 'phone') ?: data_get($payload, 'buyer.phone');
        if (filled($customerName) || filled($customerPhone)) {
            $lines[] = $this->twoColumnLine(
                'Name: '.($customerName ?: '-'),
                filled($customerPhone) ? 'Ph: '.$customerPhone : '',
                $width
            );
            $lines[] = str_repeat('-', $width);
        }

        $lines[] = $this->receiptHeaderLine($width);
        $lines[] = str_repeat('-', $width);

        foreach ($products as $index => $product) {
            $name = $this->cleanPrintText((string) data_get($product, 'name', data_get($product, 'description', 'Item')));
            $seat = data_get($product, 'seat_number');
            if (filled($seat)) {
                $name = 'S'.$seat.' '.$name;
            }
            $qty = (float) data_get($product, 'quantity', 0);
            $unitPrice = (float) data_get($product, 'unit_price', 0);
            $amount = (float) data_get($product, 'total', data_get($product, 'line_total_incl_tax', 0));
            array_push($lines, ...$this->receiptProductLines($index + 1, $name, $qty, $unitPrice, $amount, $width, $currencySubunit));
        }

        $lines[] = str_repeat('-', $width);
        $lines[] = $this->twoColumnLine(
            'Items: '.$products->count(),
            'Qty: '.$this->formatQty($totalQty),
            $width
        );
        $lines[] = str_repeat('-', $width);
        $lines[] = $this->amountLine('Sub Total', (float) $subtotal, $width, $currencySubunit);

        foreach ($discounts as $discount) {
            $label = 'Discount';
            if (filled(data_get($discount, 'name'))) {
                $label .= ' ('.data_get($discount, 'name').')';
            }
            $lines[] = $this->amountLine($label, -1 * abs((float) data_get($discount, 'amount', 0)), $width, $currencySubunit);
        }

        foreach ($taxes as $tax) {
            $lines[] = $this->amountLine((string) data_get($tax, 'name', 'Tax'), (float) data_get($tax, 'amount', 0), $width, $currencySubunit);
        }

        $lines[] = str_repeat('=', $width);
        $lines[] = $this->amountLine('NET PAYABLE', (float) $total, $width, $currencySubunit);
        $lines[] = str_repeat('=', $width);

        foreach ($payments as $payment) {
            $lines[] = $this->amountLine((string) data_get($payment, 'method', 'Payment'), (float) data_get($payment, 'amount', 0), $width, $currencySubunit);
        }

        $footer = trim((string) setting('appearance_footer_text', ''));
        if ($footer !== '') {
            $lines[] = str_repeat('-', $width);
            array_push($lines, ...array_map(fn ($line) => $this->centerText($line, $width), $this->wrapText($footer, $width)));
        }

        $lines[] = $this->centerText('Thank you! Visit again soon', $width);

        return $this->fastTextEscPosPayload($title, $lines, $type, $this->escLogoFromBase64($logo));
    }

    private function printProducts(array $payload): \Illuminate\Support\Collection
    {
        $productsPayload = data_get($payload, 'products', []);

        if (is_array($productsPayload) && array_key_exists('products', $productsPayload)) {
            return collect(data_get($productsPayload, 'products', []));
        }

        return collect($productsPayload);
    }

    private function textColumns(PrinterPaperSize $paperSize, mixed $override = null): int
    {
        $default = $paperSize === PrinterPaperSize::Paper58mm
            ? (int) config('printer.escpos.paper58_columns', 32)
            : (int) config('printer.escpos.paper80_columns', 48);

        if (! is_numeric($override)) {
            return $default;
        }

        $columns = (int) $override;

        return max(24, min(64, $columns));
    }

    private function escTrailingFeed(PrintContentType $type): string
    {
        $defaultLines = $this->boundedFeedLines((int) config('printer.escpos.trailing_feed_lines', 5));
        $lines = match (true) {
            in_array($type, [PrintContentType::Bill, PrintContentType::Invoice, PrintContentType::Delivery], true) => max(
                $defaultLines,
                $this->boundedFeedLines((int) config('printer.escpos.receipt_trailing_feed_lines', 12))
            ),
            // KOT/waiter tickets are intentionally short. Use their dedicated
            // cutter clearance instead of inheriting the longer receipt feed,
            // otherwise each auto-print leaves a large blank strip.
            in_array($type, [PrintContentType::Kitchen, PrintContentType::Waiter], true) => $this->boundedFeedLines((int) config('printer.escpos.kitchen_trailing_feed_lines', 12)),
            default => $defaultLines,
        };

        return str_repeat("\n", max(1, $lines));
    }

    private function boundedFeedLines(int $lines): int
    {
        return max(0, min(20, $lines));
    }
}
