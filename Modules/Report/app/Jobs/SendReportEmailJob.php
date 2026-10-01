<?php

namespace Modules\Report\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Report\Services\Analytics\AnalyticsService;
use Modules\Saas\Support\TenantContext;

class SendReportEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public readonly int $tenantId,
        public readonly ?int $branchId,
        public readonly string $recipient,
        public readonly string $reportType,
        public readonly string $date,
    ) {
        $this->onQueue('mail');
    }

    public function handle(AnalyticsService $analytics, TenantContext $context): void
    {
        if ($this->branchId !== null && ! DB::table('branches')->where('id', $this->branchId)->where('tenant_id', $this->tenantId)->exists()) {
            throw new \LogicException('Report email branch does not belong to the queued tenant.');
        }

        $context->setId($this->tenantId);
        try {
            $data = $analytics->getSalesAnalytics($this->date, $this->date, $this->branchId);
            $label = $this->reportType === 'financial_summary' ? 'Financial Dashboard Summary' : 'Sales Summary';
            $metrics = [
                ['Date', $this->date],
                ['Net sales', number_format((float) ($data['net_sales'] ?? 0), 2, '.', '')],
                ['Total orders', (string) ((int) ($data['total_orders'] ?? 0))],
                ['Average order value', number_format((float) ($data['avg_order_value'] ?? 0), 2, '.', '')],
            ];
            $csv = "Metric,Value\r\n".collect($metrics)
                ->map(fn (array $row) => collect($row)->map(fn ($value) => '"'.str_replace('"', '""', (string) $value).'"')->implode(','))
                ->implode("\r\n")."\r\n";

            Mail::raw("Your tenant-scoped {$label} for {$this->date} is attached. This message was generated from NexDine's audited report delivery workflow.", function (Message $message) use ($label, $csv): void {
                $message->to($this->recipient)
                    ->subject("NexDine {$label} — {$this->date}")
                    ->attachData($csv, "nexdine-{$this->reportType}-{$this->date}.csv", ['mime' => 'text/csv']);
            });
        } finally {
            $context->clear();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('Report email failed permanently', [
            'tenant_id' => $this->tenantId,
            'branch_id' => $this->branchId,
            'report_type' => $this->reportType,
            'recipient_fingerprint' => hash('sha256', strtolower($this->recipient)),
            'exception' => $exception::class,
        ]);
    }
}
