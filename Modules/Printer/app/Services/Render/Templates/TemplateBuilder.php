<?php

namespace Modules\Printer\Services\Render\Templates;

use Illuminate\View\View;
use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Enum\PrinterPaperSize;

class TemplateBuilder
{
    /**
     * Build template
     *
     * @param PrintContentType $type
     * @param array $payload
     * @param PrinterPaperSize $paperSize
     * @return View
     */
    public function build(PrintContentType $type, array $payload, PrinterPaperSize $paperSize): View
    {
        $profile = config("printer.media_profiles." . $paperSize->value);
        return view($this->getTemplateView($type), compact('payload', 'profile'));
    }

    /**
     * Get template view
     *
     * @param PrintContentType $type
     * @return string
     */
    public function getTemplateView(PrintContentType $type): string
    {
        return "print.templates." . $type->value;
    }
}
