<?php

namespace Modules\Printer\Services\Render\EscPos;

class ExperimentalEscPosInvoiceTemplate
{
    public function render(array $payload, int $width): array
    {
        $order = (array) data_get($payload, 'order', []);
        $branch = (array) data_get($payload, 'branch', []);
        $customer = (array) data_get($payload, 'customer', []);
        $table = data_get($payload, 'table');
        $currencySubunit = (int) data_get($payload, 'currency_subunit', 2);
        $products = $this->products($payload);
        $taxes = collect(data_get($payload, 'taxes', []));
        $payments = collect(data_get($payload, 'allocations', []))
            ->map(fn ($payment) => [
                'method' => data_get($payment, 'payment.method', data_get($payment, 'method', 'Payment')),
                'amount' => data_get($payment, 'amount', 0),
            ]);

        if ($payments->isEmpty()) {
            $payments = collect(data_get($payload, 'payments', []));
        }

        $discounts = collect(data_get($payload, 'discounts', []));
        $invoiceNo = data_get($payload, 'invoice_number')
            ?: data_get($order, 'order_number')
            ?: data_get($order, 'reference_no');
        $date = data_get($payload, 'issued_at.full_date')
            ?: data_get($payload, 'issued_at.date')
            ?: data_get($order, 'order_date');
        $time = data_get($payload, 'issued_at.time')
            ?: data_get($order, 'time')
            ?: data_get($order, 'scheduled_at');
        $subtotal = (float) data_get($payload, 'subtotal', data_get($order, 'subtotal', 0));
        $total = (float) data_get($payload, 'total', data_get($order, 'total', 0));
        $discountTotal = (float) data_get($payload, 'discount_total', $discounts->sum(fn ($discount) => (float) data_get($discount, 'amount', 0)));
        $totalQty = $products->sum(fn ($product) => (float) data_get($product, 'quantity', 0));

        $lines = [];
        $orderType = data_get($order, 'type');
        $tableName = data_get($table, 'name');
        $orderLabel = $this->orderLabel($orderType, $tableName);

        $lines[] = $this->line($this->center('INVOICE', $width), align: 'center', bold: true);
        $lines[] = $this->twoColumn(filled($invoiceNo) ? 'Invoice : #'.$invoiceNo : '', trim((string) $date.' '.(string) $time), $width);
        if (filled(data_get($order, 'reference_no'))) {
            $lines[] = 'Order: '.data_get($order, 'reference_no');
        }
        $lines[] = $orderLabel;

        $lines[] = str_repeat('=', $width);
        $branchName = data_get($branch, 'name') ?: data_get($branch, 'legal_name') ?: data_get($payload, 'seller.legal_name');
        if (filled($branchName)) {
            foreach ($this->branchNameLines((string) $branchName, $width) as $branchNameLine) {
                $lines[] = $this->line($this->center($branchNameLine, $width), align: 'center', bold: true, size: 'height');
            }
        }

        foreach ([
            data_get($branch, 'address_line1') ?: data_get($payload, 'seller.address_line1'),
            data_get($branch, 'address_line2') ?: data_get($payload, 'seller.address_line2'),
            filled(data_get($branch, 'phone')) ? 'Phone: '.data_get($branch, 'phone') : data_get($payload, 'seller.phone'),
        ] as $branchLine) {
            if (filled($branchLine)) {
                array_push($lines, ...$this->wrap((string) $branchLine, $width));
            }
        }

        $taxNumber = data_get($payload, 'seller.vat_tin')
            ?: data_get($branch, 'tax_number')
            ?: data_get($payload, 'seller.tax_number');
        $registrationNumber = data_get($branch, 'registration_number')
            ?: data_get($branch, 'cr_number')
            ?: data_get($payload, 'seller.cr_number')
            ?: data_get($payload, 'seller.registration_number')
            ?: $taxNumber;

        if (filled($taxNumber) || filled($registrationNumber)) {
            $lines[] = str_repeat('-', $width);
            $leftTaxLabel = filled(data_get($payload, 'seller.vat_tin')) || filled(data_get($payload, 'seller.cr_number')) ? 'TIN' : 'GST';
            $rightTaxText = filled(data_get($payload, 'seller.cr_number'))
                ? 'CR : '.$registrationNumber
                : (filled($invoiceNo) ? 'Order ID : '.$invoiceNo : (filled($registrationNumber) ? 'CR : '.$registrationNumber : ''));

            $lines[] = $this->twoColumn(filled($taxNumber) ? $leftTaxLabel.' : '.$taxNumber : '', $rightTaxText, $width);
        }

        $lines[] = str_repeat('-', $width);
        $customerName = data_get($customer, 'name') ?: data_get($payload, 'buyer.legal_name') ?: 'Walk-in Customer';
        $customerPhone = data_get($customer, 'phone') ?: data_get($payload, 'buyer.phone');
        $lines[] = $this->twoColumn('Name : '.$customerName, filled($customerPhone) ? 'Phone : '.$customerPhone : 'Phone :', $width);
        $lines[] = str_repeat('-', $width);
        $lines[] = $this->line($this->itemHeader($width), bold: true);
        $lines[] = str_repeat('-', $width);

        foreach ($products as $index => $product) {
            array_push($lines, ...$this->itemLines(
                $index + 1,
                (string) data_get($product, 'name', 'Item'),
                (float) data_get($product, 'quantity', 0),
                (float) data_get($product, 'unit_price', 0),
                (float) data_get($product, 'total', 0),
                $width,
                $currencySubunit
            ));
        }

        $lines[] = str_repeat('-', $width);
        $lines[] = $this->line($this->twoColumn('Total Item : ( '.$products->count().' )', 'Total Qty : ( '.$this->formatQty($totalQty).' )', $width), bold: true);
        $lines[] = str_repeat('-', $width);
        $lines[] = $this->amount('Bill Amount', $subtotal, $width, $currencySubunit, bold: true);
        $deliveryFee = (float) data_get($order, 'customer_delivery_fee', 0);
        $deliveryIsInvoiceLine = collect(data_get($payload, 'lines', []))->contains(function ($line): bool {
            $description = data_get($line, 'description', '');
            $description = is_array($description) ? implode(' ', $description) : (string) $description;

            return str_contains(strtolower($description), 'delivery fee');
        });
        if ($deliveryFee > 0 && ! $deliveryIsInvoiceLine) {
            $lines[] = $this->amount('Delivery Fee', $deliveryFee, $width, $currencySubunit);
        }

        if ($discountTotal > 0) {
            $lines[] = $this->amount('Discount', -1 * abs($discountTotal), $width, $currencySubunit);
        }

        foreach ($taxes as $tax) {
            $label = (string) data_get($tax, 'name', 'Tax');
            $lines[] = $this->amount($label, (float) data_get($tax, 'amount', 0), $width, $currencySubunit);
        }

        $lines[] = str_repeat('=', $width);
        $lines[] = $this->amount('Net Payable', $total, $width, $currencySubunit, bold: true, size: 'height');
        $lines[] = str_repeat('=', $width);

        if ($discountTotal > 0) {
            $lines[] = $this->line($this->center('Total Amount Save On This Bills : '.$this->formatAmount($discountTotal, $currencySubunit).'/-', $width), align: 'center', bold: true);
            $lines[] = str_repeat('-', $width);
        }

        foreach ($payments as $payment) {
            $method = (string) data_get($payment, 'method', 'Payment');
            $lines[] = $this->amount($method, (float) data_get($payment, 'amount', 0), $width, $currencySubunit);
        }

        $footer = trim((string) setting('appearance_footer_text', ''));
        $footer = $footer !== '' ? $footer : 'Thank you! Visit again soon';

        $lines[] = str_repeat('-', $width);
        foreach ($this->wrap($footer, $width) as $footerLine) {
            $lines[] = $this->line($this->center($footerLine, $width), align: 'center', bold: true);
        }

        return $lines;
    }

    private function products(array $payload): \Illuminate\Support\Collection
    {
        $lines = data_get($payload, 'lines');

        if (blank($lines)) {
            $lines = data_get($payload, 'products', []);
        }

        return collect($lines)->map(fn ($line) => [
            'name' => data_get($line, 'description', data_get($line, 'name', 'Item')),
            'quantity' => data_get($line, 'quantity', 0),
            'unit_price' => data_get($line, 'unit_price', 0),
            'total' => data_get($line, 'line_total_incl_tax', data_get($line, 'total', 0)),
        ]);
    }

    private function itemHeader(int $width): string
    {
        if ($width <= 32) {
            return $this->twoColumn('# Item', 'Amt', $width);
        }

        return $this->clip(
            str_pad('#', 3)
            .str_pad('Item', $width - 29)
            .str_pad('Qty', 7, ' ', STR_PAD_LEFT)
            .str_pad('Price', 9, ' ', STR_PAD_LEFT)
            .str_pad('Amount', 10, ' ', STR_PAD_LEFT),
            $width
        );
    }

    private function itemLines(int $index, string $name, float $quantity, float $unitPrice, float $amount, int $width, int $currencySubunit): array
    {
        $qty = $this->formatTableQty($quantity);
        $rate = $this->formatAmount($unitPrice, $currencySubunit);
        $total = $this->formatAmount($amount, $currencySubunit);

        if ($width <= 32) {
            return [
                $this->clip($index.'. '.$this->clean($name), $width),
                $this->twoColumn($qty.' x '.$rate, $total, $width),
            ];
        }

        $nameWidth = $width - 29;
        $lines = [];
        foreach ($this->wrap($name, $nameWidth) as $lineIndex => $nameLine) {
            if ($lineIndex === 0) {
                $lines[] = $this->clip(
                    str_pad((string) $index, 3)
                    .str_pad($nameLine, $nameWidth)
                    .str_pad($qty, 7, ' ', STR_PAD_LEFT)
                    .str_pad($rate, 9, ' ', STR_PAD_LEFT)
                    .str_pad($total, 10, ' ', STR_PAD_LEFT),
                    $width
                );
            } else {
                $lines[] = '   '.$this->clip($nameLine, $nameWidth);
            }
        }

        return $lines;
    }

    private function amount(string $label, float $amount, int $width, int $currencySubunit, bool $bold = false, string $size = 'normal'): string|array
    {
        $line = $this->twoColumn('', $label.' : '.$this->formatAmount($amount, $currencySubunit), $width);

        return $bold || $size !== 'normal' ? $this->line($line, bold: $bold, size: $size) : $line;
    }

    private function line(string $text, string $align = 'left', bool $bold = false, string $size = 'normal'): array
    {
        return [
            'text' => $text,
            'align' => $align,
            'bold' => $bold,
            'size' => $size,
        ];
    }

    private function orderLabel(mixed $orderType, mixed $tableName): string
    {
        $type = trim((string) $orderType);
        $table = trim((string) $tableName);

        if ($type === '' && $table === '') {
            return '';
        }

        $normalizedType = str($type)->replace(['_', '-'], ' ')->title()->toString();

        if ($table !== '') {
            return trim($normalizedType.' : '.$table);
        }

        return $normalizedType;
    }

    private function branchNameLines(string $branchName, int $width): array
    {
        $branchName = strtoupper($this->clean($branchName));
        $titleWidth = $width > 32 ? 32 : $width;

        if (str_contains($branchName, ' RESTAURANT') && strlen($branchName) > $titleWidth) {
            $withoutRestaurant = trim(str_replace(' RESTAURANT', '', $branchName));

            return array_values(array_filter([
                ...$this->wrapToWidth($withoutRestaurant, $titleWidth),
                'RESTAURANT',
            ]));
        }

        return $this->wrapToWidth($branchName, $titleWidth);
    }

    private function wrap(string $text, int $width): array
    {
        $text = $this->clean($text);
        if ($text === '') {
            return [''];
        }

        return $this->wrapToWidth($text, $width);
    }

    private function wrapToWidth(string $text, int $width): array
    {
        return array_map(
            fn ($line) => $this->clip($line, $width),
            explode("\n", wordwrap($text, $width, "\n", true))
        );
    }

    private function twoColumn(string $left, string $right, int $width): string
    {
        $left = $this->clean($left);
        $right = $this->clean($right);
        $space = max(1, $width - strlen($left) - strlen($right));

        if (strlen($left) + strlen($right) + $space > $width) {
            $left = substr($left, 0, max(0, $width - strlen($right) - 1));
            $space = 1;
        }

        return $this->clip($left.str_repeat(' ', $space).$right, $width);
    }

    private function center(string $text, int $width): string
    {
        $text = $this->clip($text, $width);

        return str_repeat(' ', max(0, intdiv($width - strlen($text), 2))).$text;
    }

    private function clip(string $text, int $width): string
    {
        return substr($this->sanitize($text), 0, $width);
    }

    private function clean(string $text): string
    {
        $text = strip_tags($text);
        $text = str_replace(['₹', '–', '—'], ['Rs', '-', '-'], $text);
        $text = preg_replace('/[^\P{C}\n]+/u', '', $text) ?? '';
        $text = preg_replace('/[^\x20-\x7E\n]/', '', $text) ?? '';
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? '';

        return trim($text);
    }

    private function sanitize(string $text): string
    {
        $text = strip_tags($text);
        $text = str_replace(['₹', '–', '—'], ['Rs', '-', '-'], $text);
        $text = preg_replace('/[^\P{C}\n]+/u', '', $text) ?? '';
        $text = preg_replace('/[^\x20-\x7E\n]/', '', $text) ?? '';

        return $text;
    }

    private function formatAmount(float $amount, int $currencySubunit): string
    {
        return number_format($amount, $currencySubunit, '.', '');
    }

    private function formatQty(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
    }

    private function formatTableQty(float $quantity): string
    {
        return number_format($quantity, 4, '.', '');
    }
}
