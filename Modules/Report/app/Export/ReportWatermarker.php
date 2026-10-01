<?php

namespace Modules\Report\Export;

use Illuminate\Support\Facades\Storage;
use Modules\User\Models\User;

class ReportWatermarker
{
    private array $config;

    public function __construct()
    {
        $this->config = [
            'enabled' => setting('reports.watermark_enabled', true),
            'text' => setting('reports.watermark_text', '{user} - {date}'),
            'opacity' => (int) setting('reports.watermark_opacity', 15),
            'font_size' => (int) setting('reports.watermark_font_size', 14),
            'position' => setting('reports.watermark_position', 'diagonal'),
        ];
    }

    public function shouldWatermark(string $reportKey, ?User $user = null): bool
    {
        if (!$this->config['enabled']) {
            return false;
        }

        // Skip watermarking for non-sensitive reports
        $nonSensitiveReports = [
            'sales',
            'products_purchase',
            'categorized_products',
            'menu_engineering',
        ];

        if (in_array($reportKey, $nonSensitiveReports)) {
            return false;
        }

        // Always watermark for sensitive financial reports
        $sensitiveReports = [
            'finance_reconciliation',
            'cost_and_revenue_by_order',
            'cost_and_revenue_by_product',
            'payments',
            'cash_movement',
            'aggregator_payout_reconciliation',
        ];

        if (in_array($reportKey, $sensitiveReports)) {
            return true;
        }

        // Check user role for other reports
        if ($user && $user->hasRole(['owner', 'admin', 'manager'])) {
            return true;
        }

        return false;
    }

    public function applyWatermark(string $filePath, string $reportKey, ?User $user = null): string
    {
        if (!$this->shouldWatermark($reportKey, $user)) {
            return $filePath;
        }

        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        if (!in_array(strtolower($extension), ['pdf', 'xlsx', 'csv'])) {
            return $filePath;
        }

        $watermarkedPath = $this->generateWatermarkedPath($filePath);

        try {
            if (strtolower($extension) === 'pdf') {
                $this->watermarkPdf($filePath, $watermarkedPath, $reportKey, $user);
            } elseif (strtolower($extension) === 'xlsx') {
                $this->watermarkExcel($filePath, $watermarkedPath, $reportKey, $user);
            } elseif (strtolower($extension) === 'csv') {
                $this->watermarkCsv($filePath, $watermarkedPath, $reportKey, $user);
            }

            return $watermarkedPath;
        } catch (\Exception $e) {
            // If watermarking fails, return original file
            \Log::warning('Report watermarking failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return $filePath;
        }
    }

    private function generateWatermarkedPath(string $originalPath): string
    {
        $pathInfo = pathinfo($originalPath);
        return $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_watermarked.' . $pathInfo['extension'];
    }

    private function watermarkPdf(string $sourcePath, string $destPath, string $reportKey, ?User $user): void
    {
        // For PDF watermarking, we would typically use a library like FPDI or TCPDF
        // For now, we'll add a text annotation to the first page
        $text = $this->getWatermarkText($reportKey, $user);

        // This is a simplified implementation
        // In production, use a proper PDF library
        $pdfContent = file_get_contents($sourcePath);
        
        // Add watermark as comment/metadata (simplified approach)
        $watermarkedContent = "% Watermarked Report\n% {$text}\n" . $pdfContent;
        
        file_put_contents($destPath, $watermarkedContent);
    }

    private function watermarkExcel(string $sourcePath, string $destPath, string $reportKey, ?User $user): void
    {
        // For Excel watermarking, we would use PhpSpreadsheet
        // This is a simplified implementation
        $text = $this->getWatermarkText($reportKey, $user);

        // Copy file and add watermark as a hidden sheet or cell comment
        copy($sourcePath, $destPath);

        // In production, use PhpSpreadsheet to add watermark
        // $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($destPath);
        // $spreadsheet->getActiveSheet()->SetCellValue('A1', $text);
        // \PhpOffice\PhpSpreadsheet\IOFactory::save($spreadsheet, $destPath);
    }

    private function watermarkCsv(string $sourcePath, string $destPath, string $reportKey, ?User $user): void
    {
        $text = $this->getWatermarkText($reportKey, $user);
        
        $lines = file($sourcePath);
        $watermarkLine = "# Watermarked: {$text}\n";
        
        // Add watermark as first line comment
        array_unshift($lines, $watermarkLine);
        
        file_put_contents($destPath, $lines);
    }

    private function getWatermarkText(string $reportKey, ?User $user): string
    {
        $text = $this->config['text'];
        
        $replacements = [
            '{user}' => $user?->name ?? 'Unknown',
            '{email}' => $user?->email ?? '',
            '{date}' => now()->toDateString(),
            '{datetime}' => now()->toDateTimeString(),
            '{report}' => $reportKey,
            '{branch}' => $user?->branch?->name ?? '',
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $text
        );
    }

    public function cleanupExpiredWatermarks(): void
    {
        // Clean up watermarked files that are older than 24 hours
        $disk = Storage::disk('local');
        
        $files = $disk->files('exports');
        
        foreach ($files as $file) {
            if (str_contains($file, '_watermarked.') && $disk->lastModified($file) < now()->subDay()->timestamp) {
                $disk->delete($file);
            }
        }
    }
}
