<?php

namespace Modules\Report\Jobs;

use BackedEnum;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Modules\Order\Models\Order;
use Modules\Payment\Enums\PaymentType;
use Modules\Report\Models\MonthlySalesReport;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class GenerateMonthlySalesReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    private const ORDER_HEADINGS = [
        'Order Number',
        'Date',
        'Time',
        'Order Type',
        'Floor',
        'Zone',
        'Table Number',
        'Customer Name',
        'Subtotal',
        'Discount Amount',
        'Tax Amount',
        'Grand Total',
        'Payment Method',
        'Order Status',
        'Waiter Name',
    ];

    /** @var string Cached highlight color from settings */
    private string $headerColor;

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    public function __construct(private readonly int $reportId)
    {
        $this->onQueue((string) config('report.monthly_sales_report.queue', 'reports'));
    }

    public function handle(): void
    {
        $claimed = MonthlySalesReport::query()
            ->whereKey($this->reportId)
            ->whereIn('status', ['pending', 'failed'])
            ->update([
                'status' => 'generating',
                'progress' => 1,
                'error_message' => null,
            ]);

        if ($claimed === 0) {
            return;
        }

        $report = MonthlySalesReport::query()->findOrFail($this->reportId);

        try {
            $this->headerColor = $this->resolveHeaderColor();

            $metrics = $this->buildWorkbook($report);

            $report->update([
                'status' => 'generated',
                'progress' => 100,
                'total_orders' => $metrics['total_orders'],
                'total_revenue' => $metrics['total_revenue'],
                'currency' => $metrics['currency'],
                'generated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $report->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function buildWorkbook(MonthlySalesReport $report): array
    {
        $month = Carbon::create($report->year, $report->month, 1);
        $spreadsheet = new Spreadsheet();
        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Monthly Summary');

        $metrics = [
            'total_orders' => 0,
            'total_sales' => 0.0,
            'total_tax' => 0.0,
            'total_discounts' => 0.0,
            'order_breakdown' => [],
            'payment_breakdown' => [],
            'tables' => [],
            'zones' => [],
            'floors' => [],
            'waiters' => [],
            'currency' => $report->currency ?: (string) (setting('default_currency', 'INR')),
        ];

        $daysInMonth = $month->daysInMonth;

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = $month->copy()->day($day);
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($date->format('d-M-Y'));
            $this->writeDailySheet($sheet, $report, $date, $metrics);

            $report->update(['progress' => min(95, (int) floor(($day / $daysInMonth) * 95))]);
        }

        $this->writeSummarySheet($summary, $report, $metrics);
        $spreadsheet->setActiveSheetIndex(0);

        $fileName = sprintf('monthly-sales-%04d-%02d.xlsx', $report->year, $report->month);
        $filePath = sprintf(
            'tenants/%s/reports/monthly-sales/%04d/%02d/%s',
            $this->tenantStorageSegment($report),
            $report->year,
            $report->month,
            $fileName
        );

        Storage::disk($report->disk)->makeDirectory(dirname($filePath));
        try {
            $writer = new Xlsx($spreadsheet);
            $writer->save(Storage::disk($report->disk)->path($filePath));
        } finally {
            $spreadsheet->disconnectWorksheets();
            $this->cleanupTemporaryFiles();
        }

        $report->update([
            'file_path' => $filePath,
            'file_name' => $fileName,
        ]);

        return [
            'total_orders' => $metrics['total_orders'],
            'total_revenue' => $metrics['total_sales'],
            'currency' => $metrics['currency'],
        ];
    }

    private function tenantStorageSegment(MonthlySalesReport $report): string
    {
        $tenantId = $report->branch?->tenant_id;

        return $tenantId ? "tenant-{$tenantId}" : "branch-{$report->branch_id}";
    }

    private function writeDailySheet($sheet, MonthlySalesReport $report, Carbon $date, array &$metrics): void
    {
        // Write headers
        $sheet->fromArray(self::ORDER_HEADINGS, null, 'A1');
        $this->styleHeaderRow($sheet, 1, count(self::ORDER_HEADINGS));
        $sheet->freezePane('A2');

        $row = 2;
        $hasOrders = false;

        $this->baseOrderQuery($report)
            ->whereDate('order_date', $date)
            ->with([
                'table.floor',
                'table.zone',
                'customer',
                'waiter',
                'discount',
                'taxes',
                'payments' => fn($query) => $query->where('type', PaymentType::Payment),
            ])
            ->orderBy('id')
            ->chunkById(500, function ($orders) use ($sheet, &$row, &$hasOrders, &$metrics) {
                foreach ($orders as $order) {
                    $hasOrders = true;
                    $subtotal = (float) ($order->getRawOriginal('subtotal') ?? 0);
                    $discount = (float) ($order->discount?->getRawOriginal('amount') ?? 0);
                    $tax = (float) $order->taxes->sum(fn($tax) => (float) ($tax->getRawOriginal('amount') ?? 0));
                    $total = (float) ($order->getRawOriginal('total') ?? 0);
                    $paymentMethods = $order->payments
                        ->pluck('method')
                        ->map(fn($method) => $method instanceof BackedEnum ? $method->value : (string) $method)
                        ->unique()
                        ->implode(', ');

                    $orderDate = $order->order_date instanceof Carbon
                        ? $order->order_date->format('Y-m-d')
                        : ($order->order_date ?: '-');
                    $createdAt = $order->created_at instanceof Carbon
                        ? $order->created_at->format('H:i:s')
                        : '-';

                    $sheet->fromArray([
                        $order->reference_no ?? '-',
                        $orderDate,
                        $createdAt,
                        $order->type?->trans() ?? $order->type?->value ?? '-',
                        $order->table?->floor?->name ?? '-',
                        $order->table?->zone?->name ?? '-',
                        $order->table?->name ?? '-',
                        $order->getCustomerName() ?: '-',
                        $subtotal,
                        $discount,
                        $tax,
                        $total,
                        $paymentMethods ?: '-',
                        $order->status?->trans() ?? $order->status?->value ?? '-',
                        $order->waiter?->name ?? '-',
                    ], null, "A{$row}");

                    $this->collectMetrics($metrics, $order, $subtotal, $discount, $tax, $total);
                    $row++;
                }
            });

        if (!$hasOrders) {
            $sheet->setCellValue('A2', 'No orders found for this date');
            $sheet->mergeCells('A2:O2');
            $sheet->getStyle('A2:O2')->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $row = 3;
        }

        $this->styleDataRows($sheet, $row - 1, count(self::ORDER_HEADINGS));
        $sheet->getStyle("I2:L{$row}")
            ->getNumberFormat()
            ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
    }

    private function collectMetrics(array &$metrics, Order $order, float $subtotal, float $discount, float $tax, float $total): void
    {
        $metrics['total_orders']++;
        $metrics['total_sales'] += $total;
        $metrics['total_tax'] += $tax;
        $metrics['total_discounts'] += $discount;

        if ($order->currency) {
            $metrics['currency'] = $order->currency;
        }

        $this->incrementMetric($metrics['order_breakdown'], $order->type?->trans() ?? (string) ($order->type?->value ?? 'Other'), 1);
        $this->incrementMetric($metrics['tables'], $order->table?->name ?? 'No Table', $total);
        $this->incrementMetric($metrics['zones'], $order->table?->zone?->name ?? 'No Zone', $total);
        $this->incrementMetric($metrics['floors'], $order->table?->floor?->name ?? 'No Floor', $total);
        $this->incrementMetric($metrics['waiters'], $order->waiter?->name ?? 'Unassigned', $total);

        foreach ($order->payments as $payment) {
            $method = $payment->method?->trans() ?? (string) ($payment->method?->value ?? 'Other');
            $this->incrementMetric($metrics['payment_breakdown'], $method, (float) ($payment->getRawOriginal('amount') ?? 0));
        }
    }

    private function writeSummarySheet($sheet, MonthlySalesReport $report, array $metrics): void
    {
        $monthLabel = Carbon::create($report->year, $report->month, 1)->format('F Y');
        $appName = (string) (setting('app_name', config('app.name')));
        $currency = $metrics['currency'];

        // Company header
        $hasLogo = $this->addLogoToSheet($sheet);
        $titleRange = $hasLogo ? 'C1:J1' : 'A1:J1';
        $sheet->setCellValue($hasLogo ? 'C1' : 'A1', "$appName - Monthly Sales Report");
        $sheet->mergeCells($titleRange);
        $sheet->getStyle($titleRange)->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle($titleRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight($hasLogo ? 48 : 22);

        // Month and generation info
        $sheet->setCellValue('A2', 'Month:');
        $sheet->setCellValue('B2', $monthLabel);
        $sheet->setCellValue('D2', 'Generated:');
        $sheet->setCellValue('E2', now()->format('Y-m-d H:i:s'));

        $sheet->getStyle('A2:B2')->getFont()->setBold(true);
        $sheet->getStyle('D2:E2')->getFont()->setBold(true);

        // --- Revenue Summary Section (starts at row 4) ---
        $avgOrderValue = $metrics['total_orders'] > 0
            ? round($metrics['total_sales'] / $metrics['total_orders'], 2)
            : 0;
        $netRevenue = round($metrics['total_sales'] - $metrics['total_tax'], 2);

        $revenueSummary = [
            ['Revenue Summary', ''],
            ['Total Orders', $metrics['total_orders']],
            ['Total Sales', $this->formatCurrency($metrics['total_sales'], $currency)],
            ['Total Tax Collected', $this->formatCurrency($metrics['total_tax'], $currency)],
            ['Total Discounts Given', $this->formatCurrency($metrics['total_discounts'], $currency)],
            ['Net Revenue', $this->formatCurrency($netRevenue, $currency)],
            ['Average Order Value', $this->formatCurrency($avgOrderValue, $currency)],
        ];

        $sheet->fromArray($revenueSummary, null, 'A4');
        // Style the section header row (row 4)
        $this->styleHeaderRow($sheet, 4, 2);
        // Style the revenue summary data range (A5:B10)
        $this->applyBorders($sheet, 4, 10, 2);
        // Apply alternating colors starting from row 5
        $this->applyAlternatingRowColors($sheet, 4, 10, 2);

        // --- Order Breakdown (starts at D4) ---
        $this->writeMetricBlockWithBorders($sheet, 'D4', 'Order Breakdown', $metrics['order_breakdown'], $currency);

        // --- Payment Breakdown (starts at G4) ---
        $this->writeMetricBlockWithBorders($sheet, 'G4', 'Payment Breakdown', $metrics['payment_breakdown'], $currency);

        // --- Top Selling Tables (starts at A14) ---
        $this->writeMetricBlockWithBorders($sheet, 'A14', 'Top Selling Tables', $metrics['tables'], $currency, true);

        // --- Top Selling Zones (starts at D14) ---
        $this->writeMetricBlockWithBorders($sheet, 'D14', 'Top Selling Zones', $metrics['zones'], $currency, true);

        // --- Top Selling Floors (starts at G14) ---
        $this->writeMetricBlockWithBorders($sheet, 'G14', 'Top Selling Floors', $metrics['floors'], $currency, true);

        // --- Best Performing Waiters (starts at J14) ---
        $this->writeMetricBlockWithBorders($sheet, 'J14', 'Best Performing Waiters', $metrics['waiters'], $currency, true);

        $filterRow = 28;
        $sheet->setCellValue("A{$filterRow}", 'Applied Filters');
        $sheet->setCellValue("B{$filterRow}", $this->formatFilters($report->filters ?: []));
        $sheet->mergeCells("B{$filterRow}:J{$filterRow}");
        $this->styleHeaderRow($sheet, $filterRow, 1);
        $this->applyBorders($sheet, $filterRow, $filterRow, 10);

        // Footer
        $footerRow = 30;
        $sheet->setCellValue("A{$footerRow}", 'Footer');
        $sheet->setCellValue("B{$footerRow}", "Generated by $appName on " . now()->format('Y-m-d H:i:s'));
        $sheet->mergeCells("B{$footerRow}:J{$footerRow}");
        $sheet->getStyle("A{$footerRow}:J{$footerRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A{$footerRow}:J{$footerRow}")
            ->getFont()->setItalic(true)->setSize(9);

        // Auto-size columns A-J
        for ($colIdx = 1; $colIdx <= 10; $colIdx++) {
            $colLetter = Coordinate::stringFromColumnIndex($colIdx);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        $this->centerAlignRange($sheet, 1, $footerRow, 'A', 'J');
    }

    /**
     * Write a metric block (title + value pairs) with proper styling.
     */
    private function writeMetricBlockWithBorders(
        $sheet,
        string $cell,
        string $title,
        array $data,
        string $currency,
        bool $valueIsCurrency = false
    ): void {
        [$column, $row] = Coordinate::coordinateFromString($cell);
        $columnIndex = Coordinate::columnIndexFromString($column);
        $valueColumn = Coordinate::stringFromColumnIndex($columnIndex + 1);

        arsort($data);
        $rows = [[$title, 'Value']];
        foreach (array_slice($data, 0, 10, true) as $label => $value) {
            $displayValue = $valueIsCurrency
                ? $this->formatCurrency((float) $value, $currency)
                : ($value === (int) $value ? (int) $value : round((float) $value, 2));
            $rows[] = [$label ?: '-', $displayValue];
        }

        if (count($rows) === 1) {
            $rows[] = ['No data', 0];
        }

        $lastRow = $row + count($rows) - 1;
        $sheet->fromArray($rows, null, $cell);

        // Style header row
        $this->styleHeaderRow($sheet, $row, 2, $column, $valueColumn);

        // Apply borders to entire block
        $this->applyBorders($sheet, $row, $lastRow, 2, $column, $valueColumn);

        // Alternating row colors (skip header)
        $this->applyAlternatingRowColors($sheet, $row, $lastRow, 2, $column, $valueColumn, true);
    }

    /**
     * Build base query for fetching orders, applying branch + date + filter constraints.
     */
    private function baseOrderQuery(MonthlySalesReport $report): Builder
    {
        $month = Carbon::create($report->year, $report->month, 1);
        $filters = $report->filters ?: [];

        return Order::query()
            ->withOutGlobalBranchPermission()
            ->when($report->branch_id, fn($query) => $query->where('branch_id', $report->branch_id))
            ->whereBetween('order_date', [
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ])
            ->when($filters['order_type'] ?? null, fn($query, $value) => $query->where('type', $value))
            ->when($filters['order_status'] ?? null, fn($query, $value) => $query->where('status', $value))
            ->when($filters['waiter_id'] ?? null, fn($query, $value) => $query->where('waiter_id', $value))
            ->when($filters['table_id'] ?? null, fn($query, $value) => $query->where('table_id', $value))
            ->when(
                $filters['floor_id'] ?? null,
                fn($query, $value) => $query->whereHas('table', fn($table) => $table->where('floor_id', $value))
            )
            ->when(
                $filters['zone_id'] ?? null,
                fn($query, $value) => $query->whereHas('table', fn($table) => $table->where('zone_id', $value))
            )
            ->when(
                $filters['payment_method'] ?? null,
                fn($query, $value) => $query->whereHas('payments', fn($payment) => $payment->where('method', $value))
            );
    }

    private function incrementMetric(array &$data, string $key, float|int $value): void
    {
        $data[$key] = ($data[$key] ?? 0) + $value;
    }

    // ─────────────────────────────────────────────
    //  Style helpers
    // ─────────────────────────────────────────────

    /**
     * Apply header styling (bold, white text, highlight color, centered) to a single row.
     */
    private function styleHeaderRow(
        $sheet,
        int $row,
        int $columns,
        string $startColumn = 'A',
        ?string $endColumn = null
    ): void {
        $startIndex = Coordinate::columnIndexFromString($startColumn);
        $endColumn ??= Coordinate::stringFromColumnIndex($startIndex + $columns - 1);
        $range = "{$startColumn}{$row}:{$endColumn}{$row}";

        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 10,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => $this->headerColor],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
    }

    /**
     * Apply thin borders to a cell range.
     */
    private function applyBorders(
        $sheet,
        int $startRow,
        int $lastRow,
        int $columns,
        string $startColumn = 'A',
        ?string $endColumn = null
    ): void {
        $startIndex = Coordinate::columnIndexFromString($startColumn);
        $endColumn ??= Coordinate::stringFromColumnIndex($startIndex + $columns - 1);
        $range = "{$startColumn}{$startRow}:{$endColumn}{$lastRow}";

        $sheet->getStyle($range)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'D9DEE3'],
                ],
            ],
        ]);

        // Center all cells in the range
        $sheet->getStyle($range)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
    }

    /**
     * Apply alternating light gray row colors to data rows (skipping the first/header row
     * of the block when $skipHeader is true).
     */
    private function applyAlternatingRowColors(
        $sheet,
        int $startRow,
        int $lastRow,
        int $columns,
        string $startColumn = 'A',
        ?string $endColumn = null,
        bool $skipHeader = false
    ): void {
        $startIndex = Coordinate::columnIndexFromString($startColumn);
        $endColumn ??= Coordinate::stringFromColumnIndex($startIndex + $columns - 1);

        $dataStart = $skipHeader ? $startRow + 1 : $startRow;

        for ($rowIdx = $dataStart; $rowIdx <= $lastRow; $rowIdx += 2) {
            $sheet->getStyle("{$startColumn}{$rowIdx}:{$endColumn}{$rowIdx}")
                ->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB('F8FAFC');
        }
    }

    /**
     * Apply borders + alternating colors + auto-size columns to data rows.
     */
    private function styleDataRows(
        $sheet,
        int $lastRow,
        int $columns,
        string $startColumn = 'A',
        ?string $endColumn = null
    ): void {
        $startIndex = Coordinate::columnIndexFromString($startColumn);
        $endColumn ??= Coordinate::stringFromColumnIndex($startIndex + $columns - 1);

        $this->applyBorders($sheet, 1, $lastRow, $columns, $startColumn, $endColumn);
        $this->applyAlternatingRowColors($sheet, 1, $lastRow, $columns, $startColumn, $endColumn, true);

        // Auto-size columns
        for ($colIdx = $startIndex; $colIdx <= Coordinate::columnIndexFromString($endColumn); $colIdx++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIdx))->setAutoSize(true);
        }
    }

    private function resolveHeaderColor(): string
    {
        $color = (string) (
            setting('appearance_table_highlight_color')
            ?: setting('appearance_primary_color', '#F57C00')
        );

        $color = ltrim(trim($color), '#');

        if (preg_match('/^[0-9a-fA-F]{3}$/', $color) === 1) {
            return strtoupper($color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2]);
        }

        if (preg_match('/^[0-9a-fA-F]{6}$/', $color) === 1) {
            return strtoupper($color);
        }

        return 'F57C00';
    }

    private function formatFilters(array $filters): string
    {
        if (empty($filters)) {
            return 'All orders';
        }

        $labels = [
            'order_type' => 'Order Type',
            'order_status' => 'Order Status',
            'payment_method' => 'Payment Method',
            'waiter_id' => 'Waiter ID',
            'table_id' => 'Table ID',
            'floor_id' => 'Floor ID',
            'zone_id' => 'Zone ID',
        ];

        return collect($filters)
            ->map(fn($value, $key) => ($labels[$key] ?? str($key)->headline()) . ': ' . $value)
            ->implode(' | ');
    }

    private function addLogoToSheet($sheet): bool
    {
        try {
            $logo = getLogoBase64();
            if (!$logo || !preg_match('/^data:(image\/(?:png|jpe?g|gif));base64,(.+)$/i', $logo, $matches)) {
                return false;
            }

            $extension = match (strtolower($matches[1])) {
                'image/png' => 'png',
                'image/gif' => 'gif',
                default => 'jpg',
            };

            $path = tempnam(sys_get_temp_dir(), 'monthly-report-logo-');
            if ($path === false) {
                return false;
            }

            $contents = base64_decode($matches[2], true);
            if ($contents === false) {
                @unlink($path);

                return false;
            }

            $logoPath = "{$path}.{$extension}";
            rename($path, $logoPath);
            file_put_contents($logoPath, $contents);
            $this->temporaryFiles[] = $logoPath;

            $drawing = new Drawing();
            $drawing->setName('Company Logo');
            $drawing->setPath($logoPath);
            $drawing->setHeight(42);
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(6);
            $drawing->setOffsetY(4);
            $drawing->setWorksheet($sheet);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function cleanupTemporaryFiles(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->temporaryFiles = [];
    }

    /**
     * Format a number as a currency string (e.g., "1,234.56 JOD").
     */
    private function formatCurrency(float $amount, string $currency): string
    {
        return number_format($amount, 2, '.', ',') . " $currency";
    }

    private function centerAlignRange(
        $sheet,
        int $startRow,
        int $lastRow,
        string $startColumn = 'A',
        string $endColumn = 'J'
    ): void {
        $sheet->getStyle("{$startColumn}{$startRow}:{$endColumn}{$lastRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
    }
}
