<?php

namespace Modules\Printer\Services\Render;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Enum\PrinterPaperSize;
use Modules\Printer\Services\Render\Templates\TemplateBuilder;
use Modules\Setting\Models\Setting;
use Modules\Setting\Repositories\SettingRepository;
use Spatie\Browsershot\Browsershot;

trait HandlesBrowserPrintRendering
{
    public function renderToImage(
        PrintContentType $type,
        array            $payload,
        PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
    ): string
    {
        $totalStartedAt = microtime(true);
        $profile = config("printer.media_profiles." . $paperSize->value, []);
        $pixelWidth = (int)($profile['pixel_width'] ?? 302);
        $deviceScaleFactor = max(1, min(3, (int) config('printer.browser.device_scale_factor', 2)));
        $timeoutSeconds = max(5, (int) config('printer.browser.timeout_seconds', 15));

        $htmlStartedAt = microtime(true);
        $html = $this->renderToHtml($type, $payload, $paperSize);
        $htmlDurationMs = $this->durationMs($htmlStartedAt);

        Log::info('Thermal print HTML rendered.', [
            'type' => $type->value,
            'template' => $this->templateView($type),
            'paper_size' => $paperSize->value,
            'duration_ms' => $htmlDurationMs,
            'html_bytes' => strlen($html),
            'branch_logo_bytes' => strlen((string) data_get($payload, 'branch.logo', '')),
            'qrcode_bytes' => strlen((string) data_get($payload, 'qrcode', '')),
            'line_count' => count((array) data_get($payload, 'lines', [])),
            'product_count' => count((array) data_get($payload, 'products', [])),
        ]);

        $browserSetupStartedAt = microtime(true);
        $browser = $this->configureBrowsershot(Browsershot::html($html))
            ->timeout($timeoutSeconds)
            ->emulateMedia('print')
            ->setScreenshotType('png')
            ->windowSize($pixelWidth, 1200)
            ->deviceScaleFactor($deviceScaleFactor)
            ->select('.paper')
            ->setOption('omitBackground', false)
            ->setOption('dithering', 'none')
            ->setOption('antialiasing', 'subpixel')
            ->setOption('args', [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--disable-extensions',
                '--disable-background-networking',
                '--disable-sync',
                '--metrics-recording-only',
                '--no-first-run',
            ]);
        $browserSetupDurationMs = $this->durationMs($browserSetupStartedAt);

        if ((bool) config('printer.browser.wait_for_network_idle', false)) {
            $browser->waitUntilNetworkIdle(false);
        }

        $imageStartedAt = microtime(true);
        $image = $browser->screenshot();

        Log::info('Thermal print image generated.', [
            'type' => $type->value,
            'template' => $this->templateView($type),
            'paper_size' => $paperSize->value,
            'browser_setup_duration_ms' => $browserSetupDurationMs,
            'image_generation_duration_ms' => $this->durationMs($imageStartedAt),
            'total_generation_duration_ms' => $this->durationMs($totalStartedAt),
            'image_bytes' => strlen($image),
            'pixel_width' => $pixelWidth,
            'device_scale_factor' => $deviceScaleFactor,
            'remote_chrome' => $this->remoteChromeIsAvailable(),
            'remote_chrome_mode' => $this->remoteChromeEndpoint() !== null ? 'ws_endpoint' : 'browser_url',
            'remote_chrome_host' => $this->remoteChromeHost(),
            'remote_chrome_port' => $this->remoteChromePort(),
            'uses_browsershot' => true,
            'uses_pdf' => false,
            'uses_full_page' => false,
        ]);

        return $image;
    }

    private function rememberRenderedImage(
        PrintContentType $type,
        array $payload,
        PrinterPaperSize $paperSize
    ): string {
        $cacheMinutes = max(0, (int) config('printer.browser.cache_minutes', 30));
        if ($cacheMinutes === 0) {
            return $this->renderToImage($type, $payload, $paperSize);
        }

        return Cache::store()->remember(
            $this->renderCacheKey($type, $payload, $paperSize),
            now()->addMinutes($cacheMinutes),
            fn() => $this->renderToImage($type, $payload, $paperSize)
        );
    }

    private function rememberRenderedEscPos(
        PrintContentType $type,
        array $payload,
        PrinterPaperSize $paperSize
    ): string {
        $cacheMinutes = max(0, (int) config('printer.browser.cache_minutes', 30));
        if ($cacheMinutes === 0) {
            return $this->imageToEscPos(
                $this->renderToImage($type, $payload, $paperSize),
                $paperSize,
                $type
            );
        }

        return Cache::store()->remember(
            $this->renderCacheKey($type, $payload, $paperSize) . ':escpos',
            now()->addMinutes($cacheMinutes),
            fn() => $this->imageToEscPos(
                $this->rememberRenderedImage($type, $payload, $paperSize),
                $paperSize,
                $type
            )
        );
    }

    private function renderCacheKey(PrintContentType $type, array $payload, PrinterPaperSize $paperSize): string
    {
        return 'printer:rendered-image:' . hash('sha256', implode('|', [
            $type->value,
            $paperSize->value,
            (string) config('printer.browser.device_scale_factor', 2),
            $this->settingsFingerprint(),
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
        ]));
    }

    private function settingsFingerprint(): string
    {
        return hash('sha256', json_encode(
            Setting::allCached()
                ->sortKeys()
                ->all(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) ?: '');
    }

    private function configureBrowsershot(Browsershot $browsershot): Browsershot
    {
        $endpoint = $this->remoteChromeEndpoint();
        if ($endpoint !== null) {
            return $browsershot->setWSEndpoint($endpoint);
        }

        if ($this->remoteChromeIsAvailable()) {
            return $browsershot->setRemoteInstance(
                $this->remoteChromeHost(),
                $this->remoteChromePort()
            );
        }

        return $this->withChromePath($browsershot);
    }

    private function withChromePath(Browsershot $browsershot): Browsershot
    {
        $configuredPath = config('printer.browser.chrome_path');
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
        if ($this->remoteChromeEndpoint() !== null) {
            return true;
        }

        $enabled = filter_var(env('PRINT_BROWSER_REMOTE_CHROME_ENABLED', true), FILTER_VALIDATE_BOOL);
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

    private function remoteChromeEndpoint(): ?string
    {
        if ($this->remoteChromeEndpoint !== null) {
            return $this->remoteChromeEndpoint;
        }

        $enabled = filter_var(env('PRINT_BROWSER_REMOTE_CHROME_ENABLED', true), FILTER_VALIDATE_BOOL);
        if (! $enabled) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 0.3,
            ],
        ]);

        $versionJson = @file_get_contents(
            sprintf('http://%s:%d/json/version', $this->remoteChromeHost(), $this->remoteChromePort()),
            false,
            $context
        );

        if (! is_string($versionJson) || $versionJson === '') {
            return null;
        }

        $version = json_decode($versionJson, true);
        $endpoint = is_array($version) ? ($version['webSocketDebuggerUrl'] ?? null) : null;

        if (! is_string($endpoint) || $endpoint === '') {
            return null;
        }

        return $this->remoteChromeEndpoint = $endpoint;
    }

    private function remoteChromeHost(): string
    {
        return (string) env('PRINT_BROWSER_REMOTE_CHROME_HOST', '127.0.0.1');
    }

    private function remoteChromePort(): int
    {
        return (int) env('PRINT_BROWSER_REMOTE_CHROME_PORT', 9222);
    }

    private function templateView(PrintContentType $type): string
    {
        return 'print.templates.' . $type->value;
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function logRenderTiming(
        string $mode,
        PrintContentType $type,
        PrinterPaperSize $paperSize,
        float $startedAt,
        int $bytes
    ): void {
        Log::info('Print render completed.', [
            'mode' => $mode,
            'type' => $type->value,
            'paper_size' => $paperSize->value,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'bytes' => $bytes,
        ]);
    }

    public function renderToHtml(
        PrintContentType $type,
        array            $payload,
        PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
    ): string
    {
        $this->refreshSettingBinding();

        return $this->build($type, $payload, $paperSize)->render();
    }

    /** @inheritDoc */
    public function build(
        PrintContentType $type,
        array            $payload,
        PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
    ): View
    {
        return (new TemplateBuilder())->build($type, $payload, $paperSize);
    }

    private function refreshSettingBinding(): void
    {
        app()->forgetInstance('setting');
        app()->singleton('setting', fn() => new SettingRepository(Setting::allCached()));
    }

    private function imageToEscPos(string $imageBytes, PrinterPaperSize $paperSize, PrintContentType $type): string
    {
        $source = imagecreatefromstring($imageBytes);
        throw_if($source === false, new \RuntimeException('Unable to decode rendered receipt image.'));

        $targetWidth = (int) config("printer.media_profiles.{$paperSize->value}.thermal_dot_width", 576);
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $targetHeight = max(1, (int) round($sourceHeight * ($targetWidth / $sourceWidth)));
        $receipt = imagecreatetruecolor($targetWidth, $targetHeight);
        throw_if($receipt === false, new \RuntimeException('Unable to allocate thermal receipt image.'));

        $white = imagecolorallocate($receipt, 255, 255, 255);
        imagefill($receipt, 0, 0, $white);
        imagecopyresampled($receipt, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        imagedestroy($source);

        $bytesPerLine = (int) ceil($targetWidth / 8);
        $raster = '';
        $bayer = [
            [0, 8, 2, 10],
            [12, 4, 14, 6],
            [3, 11, 1, 9],
            [15, 7, 13, 5],
        ];

        for ($y = 0; $y < $targetHeight; $y++) {
            for ($byteX = 0; $byteX < $bytesPerLine; $byteX++) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $x = ($byteX * 8) + $bit;
                    if ($x >= $targetWidth) {
                        continue;
                    }

                    $rgb = imagecolorat($receipt, $x, $y);
                    $red = ($rgb >> 16) & 0xFF;
                    $green = ($rgb >> 8) & 0xFF;
                    $blue = $rgb & 0xFF;
                    $luminance = (int) round(($red * 0.299) + ($green * 0.587) + ($blue * 0.114));
                    $threshold = 190 + (($bayer[$y % 4][$x % 4] - 8) * 5);

                    if ($luminance < $threshold) {
                        $byte |= 1 << (7 - $bit);
                    }
                }
                $raster .= chr($byte);
            }
        }
        imagedestroy($receipt);

        $widthLow = chr($bytesPerLine & 0xFF);
        $widthHigh = chr(($bytesPerLine >> 8) & 0xFF);
        $heightLow = chr($targetHeight & 0xFF);
        $heightHigh = chr(($targetHeight >> 8) & 0xFF);

        return "\x1B\x40"
            . "\x1B\x61\x00"
            . "\x1D\x76\x30\x00{$widthLow}{$widthHigh}{$heightLow}{$heightHigh}{$raster}"
            . $this->escTrailingFeed($type)
            . "\x1D\x56\x00";
    }
}
