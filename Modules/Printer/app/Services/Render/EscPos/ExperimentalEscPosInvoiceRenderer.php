<?php

namespace Modules\Printer\Services\Render\EscPos;

use Illuminate\Support\Facades\Log;
use Modules\Printer\Enum\PrinterPaperSize;

class ExperimentalEscPosInvoiceRenderer
{
    public function __construct(
        private readonly ExperimentalEscPosInvoiceTemplate $template = new ExperimentalEscPosInvoiceTemplate(),
    )
    {
    }

    public function render(array $payload, PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm): string
    {
        $startedAt = microtime(true);
        $width = $paperSize === PrinterPaperSize::Paper58mm ? 32 : 48;
        $lines = $this->template->render($payload, $width);

        $bytes = $this->initialize()
            . $this->renderLines($lines)
            . $this->feed(8)
            . $this->cut();

        Log::info('Experimental ESC/POS invoice rendered.', [
            'paper_size' => $paperSize->value,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'bytes' => strlen($bytes),
            'uses_browsershot' => false,
            'uses_chrome' => false,
            'uses_png' => false,
        ]);

        return $bytes;
    }

    public function renderBase64(array $payload, PrinterPaperSize $paperSize = PrinterPaperSize::Paper80mm): string
    {
        return base64_encode($this->render($payload, $paperSize));
    }

    private function initialize(): string
    {
        return "\x1B\x40" . "\x1B\x74\x00" . "\x1B\x33\x18";
    }

    private function bold(bool $enabled): string
    {
        return "\x1B\x45" . ($enabled ? "\x01" : "\x00");
    }

    private function doubleSize(bool $enabled): string
    {
        return $enabled ? "\x1D\x21\x11" : "\x1D\x21\x00";
    }

    private function textSize(string $size): string
    {
        return match ($size) {
            'height' => "\x1D\x21\x01",
            'width' => "\x1D\x21\x10",
            'double' => "\x1D\x21\x11",
            default => "\x1D\x21\x00",
        };
    }

    private function renderLines(array $lines): string
    {
        $bytes = '';

        foreach ($lines as $line) {
            if (is_array($line)) {
                $bytes .= $this->align((string) ($line['align'] ?? 'left'))
                    . $this->bold((bool) ($line['bold'] ?? true))
                    . $this->textSize((string) ($line['size'] ?? 'normal'))
                    . (string) ($line['text'] ?? '')
                    . $this->textSize('normal')
                    . $this->bold(false)
                    . $this->align('left')
                    . "\n";

                continue;
            }

            $bytes .= $this->align('left')
                . $this->bold(true)
                . $this->textSize('normal')
                . (string) $line
                . $this->bold(false)
                . "\n";
        }

        return $bytes;
    }

    private function align(string $align): string
    {
        return match ($align) {
            'center' => "\x1B\x61\x01",
            'right' => "\x1B\x61\x02",
            default => "\x1B\x61\x00",
        };
    }

    private function cut(): string
    {
        return "\x1D\x56\x42\x00";
    }

    private function feed(int $lines): string
    {
        return "\x1B\x64" . chr(max(1, min(12, $lines)));
    }
}
