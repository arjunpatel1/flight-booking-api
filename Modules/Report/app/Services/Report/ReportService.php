<?php

namespace Modules\Report\Services\Report;

use Illuminate\Http\Request;
use Modules\Report\Enums\ExportMethod;
use Modules\Report\ReportManager;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportService implements ReportServiceInterface
{
    /** @inheritDoc */
    public function renderReport(Request $request, string $key): array
    {
        $reportManager = ReportManager::getInstance();

        abort_unless(
            $reportManager->reportExists($key),
            404,
            __("report::messages.report_not_exists", ['report' => $key])
        );

        $report = $reportManager->registeredReports($key);

        abort_unless(
            auth()->user()->can($report->permission()),
            303,
            __("admin::messages.action_unauthorized")
        );

        return $report->render($request);
    }

    /** @inheritDoc */
    public function export(Request $request, string $key, ExportMethod $method): StreamedResponse|BinaryFileResponse
    {
        $reportManager = ReportManager::getInstance();

        abort_unless(
            $reportManager->reportExists($key),
            404,
            __("report::messages.report_not_exists", ['report' => $key])
        );

        $report = $reportManager->registeredReports($key);

        abort_unless(
            auth()->user()->can($report->permission()),
            303,
            __("admin::messages.action_unauthorized")
        );

        $methodName = "exportTo{$method->value}";

        abort_unless(
            method_exists($report, $methodName),
            404,
            __("report::messages.report_not_exists", ['report' => $key])
        );

        return $report->{$methodName}($request);
    }
}
