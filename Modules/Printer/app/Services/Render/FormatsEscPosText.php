<?php

namespace Modules\Printer\Services\Render;

use Modules\Printer\Enum\PrintContentType;

trait FormatsEscPosText
{
    private function wrapText(string $text, int $width): array
    {
        $text = $this->cleanPrintText($text);
        if ($text === '') {
            return [''];
        }

        $lines = [];
        foreach (explode("\n", wordwrap($text, $width, "\n", true)) as $line) {
            $lines[] = $this->clipLine($line, $width);
        }

        return $lines;
    }

    private function centerText(string $text, int $width): string
    {
        $text = $this->clipLine($this->cleanPrintText($text), $width);
        $padding = max(0, intdiv($width - strlen($text), 2));

        return str_repeat(' ', $padding) . $text;
    }

    private function keyValueLine(string $key, mixed $value, int $width): string
    {
        return $this->clipLine($key . ': ' . $this->cleanPrintText((string) $value), $width);
    }

    private function twoColumnLine(string $left, string $right, int $width): string
    {
        $left = $this->cleanPrintText($left);
        $right = $this->cleanPrintText($right);
        $space = max(1, $width - strlen($left) - strlen($right));

        if ($space === 1 && (strlen($left) + strlen($right) + 1) > $width) {
            $left = substr($left, 0, max(0, $width - strlen($right) - 1));
        }

        return $this->clipLine($left . str_repeat(' ', $space) . $right, $width);
    }

    private function receiptHeaderLine(int $width): string
    {
        if ($width <= 32) {
            return $this->twoColumnLine('# Item', 'Amt', $width);
        }

        return $this->clipLine(
            str_pad('#', 3)
            . str_pad('Item', $width - 25)
            . str_pad('Qty', 5, ' ', STR_PAD_LEFT)
            . str_pad('Rate', 9, ' ', STR_PAD_LEFT)
            . str_pad('Amt', 8, ' ', STR_PAD_LEFT),
            $width
        );
    }

    private function receiptProductLines(
        int $index,
        string $name,
        float $quantity,
        float $unitPrice,
        float $amount,
        int $width,
        int $currencySubunit
    ): array {
        $qty = $this->formatQty($quantity);
        $rate = $this->formatAmount($unitPrice, $currencySubunit);
        $total = $this->formatAmount($amount, $currencySubunit);

        if ($width <= 32) {
            return [
                $this->clipLine($index . '. ' . $name, $width),
                $this->twoColumnLine($qty . ' x ' . $rate, $total, $width),
            ];
        }

        $nameWidth = $width - 25;
        $wrapped = $this->wrapText($name, $nameWidth);
        $lines = [];
        foreach ($wrapped as $lineIndex => $nameLine) {
            if ($lineIndex === 0) {
                $lines[] = $this->clipLine(
                    str_pad($index . '.', 3)
                    . str_pad($nameLine, $nameWidth)
                    . str_pad($qty, 5, ' ', STR_PAD_LEFT)
                    . str_pad($rate, 9, ' ', STR_PAD_LEFT)
                    . str_pad($total, 8, ' ', STR_PAD_LEFT),
                    $width
                );
            } else {
                $lines[] = '   ' . $this->clipLine($nameLine, $nameWidth);
            }
        }

        return $lines;
    }

    private function amountLine(string $label, float $amount, int $width, int $currencySubunit): string
    {
        return $this->twoColumnLine($label, $this->formatAmount($amount, $currencySubunit), $width);
    }

    private function formatAmount(float $amount, int $currencySubunit): string
    {
        return number_format($amount, $currencySubunit, '.', '');
    }

    private function formatQty(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
    }

    private function clipLine(string $text, int $width): string
    {
        // At this stage the caller may already have inserted significant
        // padding for fixed-width thermal-printer columns. Running the line
        // through cleanPrintText() would collapse that padding and make Qty,
        // Rate, Amount and totals appear as an unaligned sentence.
        return substr($this->cleanFormattedPrintLine($text), 0, $width);
    }

    private function cleanFormattedPrintLine(string $text): string
    {
        $text = preg_replace('/[^\P{C}\t]+/u', '', strip_tags($text)) ?? '';

        return str_replace("\t", ' ', $text);
    }

    private function cleanPrintText(string $text): string
    {
        $text = preg_replace('/[^\P{C}\n]+/u', '', strip_tags($text)) ?? '';
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? '';

        return trim($text);
    }

    private function escInit(): string
    {
        return "\x1B\x40" . "\x1B\x74\x00" . "\x1B\x33\x18";
    }

    private function escBold(bool $enabled): string
    {
        return "\x1B\x45" . ($enabled ? "\x01" : "\x00");
    }

    private function escDouble(bool $enabled): string
    {
        return $enabled ? "\x1D\x21\x11" : "\x1D\x21\x00";
    }

    private function escAlign(string $align): string
    {
        return match ($align) {
            'center' => "\x1B\x61\x01",
            'right' => "\x1B\x61\x02",
            default => "\x1B\x61\x00",
        };
    }

    private function escCut(): string
    {
        return "\x1D\x56\x00";
    }

    private function fastTextEscPosPayload(
        string $title,
        array $lines,
        PrintContentType $type,
        string $logoPayload = '',
        string $headerLabel = ''
    ): string
    {
        return $this->escInit()
            . $logoPayload
            . $this->escAlign('center')
            . ($headerLabel !== ''
                ? $this->escBold(true) . $this->cleanPrintText($headerLabel) . "\n" . $this->escBold(false)
                : '')
            . $this->escBold(true)
            . $this->escDouble(true)
            . $this->cleanPrintText($title)
            . "\n"
            . $this->escDouble(false)
            . $this->escBold(false)
            . $this->escAlign('left')
            . implode("\n", $lines)
            . $this->escTrailingFeed($type)
            . $this->escCut();
    }

    private function escLogoFromBase64(mixed $logo): string
    {
        if (! (bool) config('printer.escpos.logo_enabled', true) || blank($logo)) {
            return '';
        }

        if (! function_exists('imagecreatefromstring')) {
            return '';
        }

        $logo = (string) $logo;
        if (str_contains($logo, ',')) {
            $logo = substr($logo, strpos($logo, ',') + 1);
        }

        $imageBytes = base64_decode($logo, true);
        if ($imageBytes === false || $imageBytes === '') {
            return '';
        }

        $source = @imagecreatefromstring($imageBytes);
        if ($source === false) {
            return '';
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            imagedestroy($source);
            return '';
        }

        $maxWidth = max(64, min(384, (int) config('printer.escpos.logo_max_width_dots', 192)));
        $maxHeight = max(48, min(240, (int) config('printer.escpos.logo_max_height_dots', 160)));
        $scale = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight, 1);
        $targetWidth = max(8, (int) floor(($sourceWidth * $scale) / 8) * 8);
        $targetHeight = max(1, (int) round($sourceHeight * ($targetWidth / $sourceWidth)));

        $logoImage = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($logoImage === false) {
            imagedestroy($source);
            return '';
        }

        $white = imagecolorallocate($logoImage, 255, 255, 255);
        imagefill($logoImage, 0, 0, $white);
        imagecopyresampled($logoImage, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        imagedestroy($source);

        // Many restaurant logos are supplied as light artwork on a dark square.
        // Printing that literally wastes ribbon/paper and hides fine details.
        // Detect a dark background from the four corners and invert luminance so
        // the thermal result becomes dark artwork on clean white paper.
        $cornerLuminance = function (int $x, int $y) use ($logoImage): int {
            $rgb = imagecolorat($logoImage, $x, $y);
            return (int) round(
                ((($rgb >> 16) & 0xFF) * 0.299)
                + ((($rgb >> 8) & 0xFF) * 0.587)
                + (($rgb & 0xFF) * 0.114)
            );
        };
        $invertDarkBackground = collect([
            $cornerLuminance(0, 0),
            $cornerLuminance($targetWidth - 1, 0),
            $cornerLuminance(0, $targetHeight - 1),
            $cornerLuminance($targetWidth - 1, $targetHeight - 1),
        ])->average() < 110;

        $bytesPerLine = (int) ceil($targetWidth / 8);
        $raster = '';
        for ($y = 0; $y < $targetHeight; $y++) {
            for ($byteX = 0; $byteX < $bytesPerLine; $byteX++) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $x = ($byteX * 8) + $bit;
                    if ($x >= $targetWidth) {
                        continue;
                    }

                    $rgb = imagecolorat($logoImage, $x, $y);
                    $red = ($rgb >> 16) & 0xFF;
                    $green = ($rgb >> 8) & 0xFF;
                    $blue = $rgb & 0xFF;
                    $luminance = (int) round(($red * 0.299) + ($green * 0.587) + ($blue * 0.114));
                    if ($invertDarkBackground) {
                        $luminance = 255 - $luminance;
                    }
                    if ($luminance < 190) {
                        $byte |= 1 << (7 - $bit);
                    }
                }
                $raster .= chr($byte);
            }
        }
        imagedestroy($logoImage);

        $widthLow = chr($bytesPerLine & 0xFF);
        $widthHigh = chr(($bytesPerLine >> 8) & 0xFF);
        $heightLow = chr($targetHeight & 0xFF);
        $heightHigh = chr(($targetHeight >> 8) & 0xFF);

        return $this->escAlign('center')
            . "\x1D\x76\x30\x00{$widthLow}{$widthHigh}{$heightLow}{$heightHigh}{$raster}"
            . "\n"
            . $this->escAlign('left');
    }
}
