<?php

namespace Modules\Invoice\Services\InvoicePDF;

use Illuminate\Http\Response;
use Spatie\Browsershot\Exceptions\CouldNotTakeBrowsershot;
use Throwable;

interface InvoicePDFServiceInterface
{
    /**
     * View invoice as pdf file
     *
     * @param string $uuid
     * @return Response
     * @throws Throwable
     * @throws CouldNotTakeBrowsershot
     */
    public function view(string $uuid): Response;

    /**
     * Download invoice as pdf file
     *
     * @param string $uuid
     * @return Response
     * @throws Throwable
     * @throws CouldNotTakeBrowsershot
     */
    public function download(string $uuid): Response;
}
