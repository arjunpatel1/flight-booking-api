<?php

namespace Modules\Invoice\Services\InvoicePDF;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Invoice\Models\Invoice;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Spatie\Browsershot\Browsershot;
use Throwable;

class InvoicePDFService implements InvoicePDFServiceInterface
{
    private const PDF_TEMPLATE_VERSION = 3;

    /** @inheritDoc */
    public function view(string $uuid): Response
    {
        $invoice = $this->getInvoice($uuid);

        $content = $this->getContent($invoice);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="invoice-' . $invoice->invoice_number . '.pdf"',
        ]);
    }

    /**
     * Get invoice
     *
     * @param string $uuid
     * @return Invoice
     */
    private function getInvoice(string $uuid): Invoice
    {
        $invoice = Invoice::query()
            ->where('uuid', $uuid)
            ->firstOrFail();

        if ($this->shouldGenerate($invoice)) {
            $invoice = Invoice::query()
                ->with($this->invoicePdfRelations())
                ->where('uuid', $uuid)
                ->firstOrFail();
        }

        return $invoice;
    }

    /**
     * Get invoice pdf content
     *
     * @param Invoice $invoice
     * @return string
     * @throws Throwable
     */
    private function getContent(Invoice $invoice): string
    {
        if ($this->shouldGenerate($invoice)) {
            $invoice->loadMissing($this->invoicePdfRelations());
            $content = $this->saveInvoiceFile($invoice);
        } else {
            $content = Storage::disk($invoice->file_info['disk'])->get($invoice->file_info['path']);
        }

        return $content;
    }

    /**
     * Store invoice as pdf file
     *
     * @param Invoice $invoice
     * @return string
     * @throws Throwable
     */
    private function saveInvoiceFile(Invoice $invoice): string
    {
        $totalStartedAt = microtime(true);
        $htmlStartedAt = microtime(true);
        $tenantContext = app(TenantContext::class);
        $settings = app(SettingServiceInterface::class);
        $previousTenantId = $tenantContext->id();
        $tenantContext->setId((int) $invoice->branch->tenant_id);
        $settings->refreshSettingBinding();
        try {
            $html = view('invoices.pdf', compact('invoice'))->render();
        } finally {
            $tenantContext->setId($previousTenantId);
            $settings->refreshSettingBinding();
        }
        $htmlDurationMs = $this->durationMs($htmlStartedAt);

        $browserSetupStartedAt = microtime(true);
        $browser = $this->configureBrowsershot(Browsershot::html($html))
            ->format('A4')
            ->margins(5, 5, 5, 5);
        $browserSetupDurationMs = $this->durationMs($browserSetupStartedAt);

        $pdfStartedAt = microtime(true);
        $pdfContent = $browser->pdf();
        $pdfDurationMs = $this->durationMs($pdfStartedAt);

        $path = "invoices/$invoice->invoice_number.pdf";
        $disk = 'local';

        if (Storage::disk($disk)->put($path, $pdfContent)) {
            $invoice->update(['file_info' => [
                "disk" => $disk,
                "path" => $path,
                "template_version" => self::PDF_TEMPLATE_VERSION,
            ]]);
        }

        Log::info('Invoice PDF generated.', [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'html_duration_ms' => $htmlDurationMs,
            'browsershot_startup_duration_ms' => $browserSetupDurationMs,
            'pdf_generation_duration_ms' => $pdfDurationMs,
            'total_generation_duration_ms' => $this->durationMs($totalStartedAt),
            'remote_chrome' => $this->remoteChromeIsAvailable(),
            'remote_chrome_host' => $this->remoteChromeHost(),
            'remote_chrome_port' => $this->remoteChromePort(),
            'bytes' => strlen($pdfContent),
        ]);

        return $pdfContent;
    }

    private function configureBrowsershot(Browsershot $browsershot): Browsershot
    {
        $timeoutSeconds = max(5, (int) env('INVOICE_PDF_BROWSER_TIMEOUT_SECONDS', 30));
        // Chromium runs without a usable user-namespace sandbox on many managed
        // servers. Browsershot executes a local, trusted renderer only, so use its
        // supported no-sandbox mode rather than failing public invoice generation.
        $browsershot->timeout($timeoutSeconds)->noSandbox();

        if ($this->remoteChromeIsAvailable()) {
            return $browsershot->setRemoteInstance(
                $this->remoteChromeHost(),
                $this->remoteChromePort()
            );
        }

        $configuredPath = env('BROWSERSHOT_CHROME_PATH', env('CHROME_PATH'));
        if (is_string($configuredPath) && $configuredPath !== '' && is_executable($configuredPath)) {
            return $browsershot->setChromePath($configuredPath);
        }

        foreach ([
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium-browser',
            '/usr/bin/chromium',
            '/snap/bin/chromium',
        ] as $path) {
            if (is_executable($path)) {
                return $browsershot->setChromePath($path);
            }
        }

        return $browsershot;
    }

    private function remoteChromeIsAvailable(): bool
    {
        $enabled = filter_var(env('INVOICE_PDF_REMOTE_CHROME_ENABLED', true), FILTER_VALIDATE_BOOL);
        if (! $enabled) {
            return false;
        }

        $connection = @fsockopen(
            $this->remoteChromeHost(),
            $this->remoteChromePort(),
            $errorCode,
            $errorMessage,
            0.2
        );

        if ($connection === false) {
            return false;
        }

        fclose($connection);

        return true;
    }

    private function remoteChromeHost(): string
    {
        return (string) env('INVOICE_PDF_REMOTE_CHROME_HOST', '127.0.0.1');
    }

    private function remoteChromePort(): int
    {
        return (int) env('INVOICE_PDF_REMOTE_CHROME_PORT', 9222);
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function shouldGenerate(Invoice $invoice): bool
    {
        if (is_null($invoice->file_info)) {
            return true;
        }

        if ((int) data_get($invoice->file_info, 'template_version', 0) < self::PDF_TEMPLATE_VERSION) {
            return true;
        }

        $disk = data_get($invoice->file_info, 'disk');
        $path = data_get($invoice->file_info, 'path');

        return ! is_string($disk)
            || ! is_string($path)
            || ! Storage::disk($disk)->exists($path);
    }

    private function invoicePdfRelations(): array
    {
        return [
            "seller",
            "buyer",
            "discounts",
            "taxes",
            "allocations" => fn($query) => $query->with(["payment"]),
            "lines",
            "branch:id,name,tenant_id",
            "referenceInvoice:id,invoice_number,uuid"
        ];
    }

    /** @inheritDoc */
    public function download(string $uuid): Response
    {
        $invoice = $this->getInvoice($uuid);

        $content = $this->getContent($invoice);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="invoice-' . $invoice->invoice_number . '.pdf"',
        ]);
    }
}
