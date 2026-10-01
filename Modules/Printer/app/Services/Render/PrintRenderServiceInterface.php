<?php

namespace Modules\Printer\Services\Render;

use Illuminate\View\View;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Enum\PrinterPaperSize;
use Throwable;

interface PrintRenderServiceInterface
{
    /**
     * Render template to base64
     *
     * @param PrintContentType $type
     * @param array $payload
     * @param PrinterPaperSize $paperSize
     * @return string
     * @throws Throwable
     */
    public function renderToBase64(
        PrintContentType $type,
        array            $payload,
        PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
    ): string;

    /**
     * Render a receipt as base64-encoded ESC/POS raster bytes.
     */
    public function renderToEscPosBase64(
        PrintContentType $type,
        array            $payload,
        PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
    ): string;

    /**
     * Render template to image
     *
     * @param PrintContentType $type
     * @param array $payload
     * @param PrinterPaperSize $paperSize
     * @return string
     * @throws Throwable
     */
    public function renderToImage(
        PrintContentType $type,
        array            $payload,
        PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
    ): string;
    
    /**
     * Render template to html
     *
     * @param PrintContentType $type
     * @param array $payload
     * @param PrinterPaperSize $paperSize
     * @return string
     * @throws Throwable
     */
    public function renderToHtml(
        PrintContentType $type,
        array            $payload,
        PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
    ): string;

    /**
     * Global Render template
     *
     * @param PrintContentType $type
     * @param array $payload
     * @param PrinterPaperSize $paperSize
     * @return View
     */
    public function build(
        PrintContentType $type,
        array            $payload,
        PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm
    ): View;
}
