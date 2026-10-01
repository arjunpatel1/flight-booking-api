<?php

namespace Modules\User\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Branch\Models\Branch;
use Modules\Support\ApiResponse;
use Modules\Report\Export\ExcelGlobalExport;
use Modules\User\Services\EmployeePayroll\EmployeePayrollServiceInterface;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Blade;
use Spatie\Browsershot\Browsershot;

class EmployeePayrollController extends Controller
{
    public function __construct(protected EmployeePayrollServiceInterface $service)
    {
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filters.branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'filters.from' => ['nullable', 'date'],
            'filters.to' => ['nullable', 'date', 'after_or_equal:filters.from'],
        ]);

        return ApiResponse::success($this->service->preview($validated['filters'] ?? []));
    }

    public function meta(): JsonResponse
    {
        return ApiResponse::success([
            'branches' => Branch::list(),
            'statuses' => collect(['draft', 'approved', 'paid', 'void'])
                ->map(fn(string $status) => [
                    'id' => $status,
                    'name' => __("user::employee_payroll.statuses.{$status}"),
                ])
                ->values(),
        ]);
    }

    public function runs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filters.branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'filters.status' => ['nullable', 'string', 'in:draft,approved,paid,void'],
        ]);

        return ApiResponse::success($this->service->runs($validated['filters'] ?? []));
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success($this->service->show($id));
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'deductions' => ['nullable', 'array'],
            'deductions.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'deductions.*.type' => ['nullable', 'string', 'max:50'],
            'deductions.*.label' => ['nullable', 'string', 'max:255'],
            'deductions.*.amount' => ['required', 'numeric', 'min:0'],
            'deductions.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return ApiResponse::success($this->service->generate($data));
    }

    public function approve(int $id): JsonResponse
    {
        return ApiResponse::success($this->service->approve($id));
    }

    public function markPaid(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['required', 'string', 'in:cash,bank_transfer,upi,cheque,other'],
            'payment_reference' => ['nullable', 'string', 'max:160'],
            'paid_at' => ['required', 'date', 'before_or_equal:now'],
        ]);

        return ApiResponse::success($this->service->markPaid($id, $data));
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return ApiResponse::success($this->service->void($id, $data['reason']));
    }

    public function statutoryExport(Request $request, int $id): JsonResponse|BinaryFileResponse
    {
        $format = $request->validate(['format' => ['nullable', 'string', 'in:json,csv,xlsx']])['format'] ?? 'json';
        $rows = $this->service->statutoryExport($id);
        if ($format === 'json') {
            return ApiResponse::success($rows);
        }

        $headings = [
            'Employee ID', 'Employee', 'Period From', 'Period To', 'Gross Amount',
            'Deductions', 'Net Amount', 'PF Amount', 'ESI Amount', 'TDS Amount',
        ];
        $columns = ['employee_id', 'employee_name', 'period_from', 'period_to', 'gross_amount', 'deductions', 'net_amount', 'pf_amount', 'esi_amount', 'tds_amount'];
        $data = collect($rows)->map(fn (array $row) => collect($columns)->map(fn (string $column) => $row[$column] ?? null)->all());
        $writer = $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX;

        return \Excel::download(new ExcelGlobalExport($headings, $data), "payroll-run-{$id}.{$format}", $writer);
    }

    public function payslipsPdf(int $id): Response
    {
        $run = $this->service->show($id);
        $html = Blade::render(<<<'BLADE'
<!doctype html><html><head><meta charset="utf-8"><style>
body{font-family:Arial,sans-serif;color:#172033;font-size:12px;margin:24px}.slip{page-break-after:always}.slip:last-child{page-break-after:auto}
h1{font-size:22px;margin:0 0 4px}.muted{color:#667085}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin:18px 0}.box{border:1px solid #d0d5dd;border-radius:6px;padding:10px}
table{width:100%;border-collapse:collapse;margin-top:14px}th,td{padding:8px;border-bottom:1px solid #e4e7ec;text-align:right}th:first-child,td:first-child{text-align:left}.total{font-size:16px;font-weight:700}
</style></head><body>
@foreach($run['payslips'] as $payslip)<section class="slip"><h1>Employee Payslip</h1><div class="muted">Payroll run #{{ $run['id'] }} · {{ $run['period_from'] }} to {{ $run['period_to'] }}</div>
<div class="grid"><div class="box"><strong>Employee</strong><br>{{ $payslip['user']['name'] }}</div><div class="box"><strong>Status</strong><br>{{ ucfirst($run['status']) }}</div></div>
<table><tr><th>Description</th><th>Amount</th></tr><tr><td>Base pay</td><td>{{ number_format($payslip['base_amount'],2) }}</td></tr><tr><td>Overtime</td><td>{{ number_format($payslip['overtime_amount'],2) }}</td></tr><tr><td>Gross pay</td><td>{{ number_format($payslip['gross_amount'],2) }}</td></tr>
@foreach($payslip['deductions'] as $deduction)<tr><td>{{ $deduction['label'] }}</td><td>-{{ number_format($deduction['amount'],2) }}</td></tr>@endforeach
<tr class="total"><td>Net pay</td><td>{{ number_format($payslip['net_amount'],2) }}</td></tr></table>
<p class="muted">Worked {{ intdiv($payslip['worked_minutes'],60) }}h {{ $payslip['worked_minutes'] % 60 }}m · Overtime {{ intdiv($payslip['overtime_minutes'],60) }}h {{ $payslip['overtime_minutes'] % 60 }}m</p></section>@endforeach
</body></html>
BLADE, ['run' => $run]);

        $browser = Browsershot::html($html)->noSandbox()->format('A4')->margins(8, 8, 8, 8)->timeout(max(5, (int) env('INVOICE_PDF_BROWSER_TIMEOUT_SECONDS', 30)));
        $remoteHost = (string) env('INVOICE_PDF_REMOTE_CHROME_HOST', '127.0.0.1');
        $remotePort = (int) env('INVOICE_PDF_REMOTE_CHROME_PORT', 9222);
        $connection = filter_var(env('INVOICE_PDF_REMOTE_CHROME_ENABLED', true), FILTER_VALIDATE_BOOL)
            ? @fsockopen($remoteHost, $remotePort, $errorCode, $errorMessage, 0.2)
            : false;
        if ($connection !== false) {
            fclose($connection);
            $browser->setRemoteInstance($remoteHost, $remotePort);
        } elseif (is_string($chrome = env('BROWSERSHOT_CHROME_PATH', env('CHROME_PATH'))) && $chrome !== '' && is_executable($chrome)) {
            $browser->setChromePath($chrome);
        } else {
            foreach (['/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium-browser', '/usr/bin/chromium', '/snap/bin/chromium'] as $path) {
                if (is_executable($path)) {
                    $browser->setChromePath($path);
                    break;
                }
            }
        }

        return response($browser->pdf(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=payroll-run-{$id}-payslips.pdf",
        ]);
    }
}
