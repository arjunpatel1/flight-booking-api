<?php

namespace Modules\Printer\Services\Render;

use Illuminate\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Enum\PrinterPaperSize;
use Modules\Printer\Services\Render\Templates\TemplateBuilder;
use Modules\Setting\Models\Setting;
use Modules\Setting\Repositories\SettingRepository;
use Spatie\Browsershot\Browsershot;


class PrintRenderService implements PrintRenderServiceInterface
{
    use FormatsEscPosText;
    use HandlesBrowserPrintRendering;
    use RendersFastEscPosText;

    private ?string $remoteChromeEndpoint = null;

    /** @inheritDoc */
    public function renderToBase64(
        PrintContentType $type,
        array            $payload,
        PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
    ): string
    {
        $startedAt = microtime(true);
        $rendered = $this->rememberRenderedImage($type, $payload, $paperSize);
        $this->logRenderTiming('image', $type, $paperSize, $startedAt, strlen($rendered));

        return base64_encode($rendered);
    }

    /** @inheritDoc */
    public function renderToEscPosBase64(
        PrintContentType $type,
        array            $payload,
        PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
    ): string
    {
        $startedAt = microtime(true);
        $rendered = $this->usesFastEscPosText($type)
            ? $this->renderFastTextEscPos($type, $payload, $paperSize)
            : $this->rememberRenderedEscPos($type, $payload, $paperSize);
        $this->logRenderTiming(
            $this->usesFastEscPosText($type) ? 'escpos-text' : 'escpos-image',
            $type,
            $paperSize,
            $startedAt,
            strlen($rendered)
        );

        return base64_encode($rendered);
    }

    /** @inheritDoc */





    /** @inheritDoc */


    /**
     * Convert the high-resolution rendered receipt to an ESC/POS GS v 0 raster
     * payload. Thermal printers require printer commands rather than JPEG bytes.
     */
}
