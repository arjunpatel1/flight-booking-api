<?php

namespace Modules\Printer\Services\Dispatcher;

use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Enum\PrinterConnectionType;
use Modules\Printer\Enum\PrinterPaperSize;
use Modules\Printer\Models\Printer;
use Modules\Printer\Services\Render\EscPos\ExperimentalEscPosInvoiceRenderer;
use Modules\Printer\Services\Render\PrintRenderServiceInterface;

readonly class PrintPayloadRenderer
{
    public function __construct(
        private PrintRenderServiceInterface $renderer,
        private ExperimentalEscPosInvoiceRenderer $experimentalEscPosInvoiceRenderer,
    ) {}

    public function render(
        PrintContentType $type,
        array $payload,
        PrinterPaperSize $paperSize,
        bool $isEscPosPayload,
        bool $useExperimentalEscPos,
    ): string {
        if ($useExperimentalEscPos) {
            return $this->experimentalEscPosInvoiceRenderer->renderBase64($payload, $paperSize);
        }

        return $isEscPosPayload
            ? $this->renderer->renderToEscPosBase64($type, $payload, $paperSize)
            : $this->renderer->renderToBase64($type, $payload, $paperSize);
    }

    /**
     * Add hardware signals only to raw ESC/POS kitchen payloads.
     * ESC B is supported by the common Epson-compatible KOT printers; devices
     * without a buzzer safely ignore the command.
     */
    public function addDeviceSignals(
        PrintContentType $type,
        array $config,
        string $renderedBase64,
        bool $isEscPosPayload,
    ): string {
        if ($type !== PrintContentType::Kitchen
            || ! $isEscPosPayload
            || ! (bool) data_get($config, 'settings.beep', false)) {
            return $renderedBase64;
        }

        $bytes = base64_decode($renderedBase64, true);
        if ($bytes === false) {
            return $renderedBase64;
        }

        // BEL covers compact KOT printers that expose only the standard alert
        // control character. ESC B keeps the three-pulse hardware buzzer path
        // for Epson-compatible devices. Unsupported controls are safely
        // ignored by ESC/POS firmware.
        return base64_encode("\x07\x1B\x42\x03\x03".$bytes);
    }

    public function usesExperimentalEscPos(PrintContentType $type): bool
    {
        return config('printer.engine', 'image') === 'escpos'
            && in_array($type, [PrintContentType::Invoice, PrintContentType::Bill], true);
    }

    public function forcesFastEscPosText(PrintContentType $type): bool
    {
        return in_array($type, [
            PrintContentType::Kitchen,
            PrintContentType::Waiter,
            PrintContentType::Bill,
            PrintContentType::Invoice,
        ], true);
    }

    public function isEscPosPayload(
        Printer $printer,
        array $config,
        bool $useExperimentalEscPos,
        bool $forceFastEscPosText,
    ): bool {
        return in_array($printer->connection_type, [
            PrinterConnectionType::Tcp,
            PrinterConnectionType::UsbRaw,
            PrinterConnectionType::Bluetooth,
        ], true) || (
            $printer->connection_type === PrinterConnectionType::Spooler
            && (bool) data_get($config, 'settings.raw', false)
        ) || $useExperimentalEscPos || $forceFastEscPosText;
    }
}
