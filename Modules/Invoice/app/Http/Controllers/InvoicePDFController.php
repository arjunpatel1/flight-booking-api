<?php

namespace Modules\Invoice\Http\Controllers;

use Illuminate\Http\Response;
use Modules\Core\Http\Controllers\Controller;
use Modules\Invoice\Services\InvoicePDF\InvoicePDFServiceInterface;
use Spatie\Browsershot\Exceptions\CouldNotTakeBrowsershot;
use Throwable;

class InvoicePDFController extends Controller
{
    /**
     * Create a new instance of InvoicePDFController
     *
     * @param InvoicePDFServiceInterface $service
     */
    public function __construct(protected InvoicePDFServiceInterface $service)
    {
    }

    /**
     * This method render pdf file
     *
     * @param string $uuid
     * @return Response
     * @throws Throwable
     * @throws CouldNotTakeBrowsershot
     */
    public function index(string $uuid): Response
    {
        return $this->service->view($uuid);
    }

    /**
     * This method download pdf file
     *
     * @param string $uuid
     * @return Response
     * @throws Throwable
     * @throws CouldNotTakeBrowsershot
     */
    public function download(string $uuid): Response
    {
        return $this->service->download($uuid);
    }
}
