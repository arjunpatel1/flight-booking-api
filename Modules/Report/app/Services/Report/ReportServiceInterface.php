<?php

namespace Modules\Report\Services\Report;

use Illuminate\Http\Request;
use Modules\Report\Enums\ExportMethod;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface ReportServiceInterface
{
    /**
     * Get report instance
     *
     * @param Request $request
     * @param string $key
     * @return array
     */
    public function renderReport(Request $request, string $key): array;

    /**
     * Get report instance
     *
     * @param Request $request
     * @param string $key
     * @param ExportMethod $method
     * @return StreamedResponse|BinaryFileResponse
     */
    public function export(Request $request, string $key, ExportMethod $method): StreamedResponse|BinaryFileResponse;
}
