<?php

namespace Modules\Report\Traits;

use Excel;
use Illuminate\Http\Request;
use Modules\Report\Enums\ExportMethod;
use Modules\Report\Export\ExcelGlobalExport;
use PhpOffice\PhpSpreadsheet\Writer\Exception;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait HasExportReport
{
    /**
     * Export report to JSON
     *
     * @param Request $request
     * @return StreamedResponse
     */
    public function exportToJson(Request $request): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($request) {
                echo $this->data($request, false)->toJson(JSON_UNESCAPED_UNICODE);
            },
            $this->getExportFileName(ExportMethod::Json)
        );
    }

    /**
     * Get export file name
     *
     * @param ExportMethod $method
     * @return string
     */
    public function getExportFileName(ExportMethod $method): string
    {
        return "{$this->key()}-" . date("Y-m-d_h:i-a") . "." . $method->value;
    }

    /**
     * Export report to XLSX
     *
     * @param Request $request
     * @return BinaryFileResponse
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @throws Exception
     */
    public function exportToXlsx(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new ($this->excelClass())($this->transAttributes(), $this->data($request, false)),
            $this->getExportFileName(ExportMethod::Xlsx),
        );
    }

    /**
     * Get export excel class
     *
     * @return string
     */
    public function excelClass(): string
    {
        return ExcelGlobalExport::class;
    }

    /**
     * Export report to XLS
     *
     * @param Request $request
     * @return BinaryFileResponse
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @throws Exception
     */
    public function exportToXls(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new ($this->excelClass())($this->transAttributes(), $this->data($request, false)),
            $this->getExportFileName(ExportMethod::Xls),
        );
    }

    /**
     * Export report to CSV
     *
     * @param Request $request
     * @return BinaryFileResponse
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @throws Exception
     */
    public function exportToCsv(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new ($this->excelClass())($this->transAttributes(), $this->data($request, false)),
            $this->getExportFileName(ExportMethod::Csv),
            null,
            ['Content-Type' => 'text/csv']
        );
    }

    /**
     * Get export methods
     *
     * @return array
     */
    public function exportMethods(): array
    {
        return ExportMethod::toArrayTrans();
    }
}
