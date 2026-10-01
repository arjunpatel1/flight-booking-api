<?php

namespace Modules\WhatsAppCenter\Services;

use Modules\Notification\Jobs\SendWhatsAppMessageJob;
use Modules\Report\Services\Analytics\AnalyticsService;

class WhatsAppReportShareService
{
    public const SHAREABLE_REPORTS = [
        'sales_summary'      => 'Sales Summary',
        'financial_summary'  => 'Financial Dashboard Summary',
    ];

    public function __construct(protected AnalyticsService $analytics) {}

    public function getReportTypes(): array
    {
        return collect(self::SHAREABLE_REPORTS)
            ->map(fn($label, $key) => ['key' => $key, 'label' => $label])
            ->values()
            ->all();
    }

    public function share(array $data): array
    {
        $tenantId  = isset($data['tenant_id']) ? (int) $data['tenant_id'] : null;
        if (! $tenantId) {
            throw new \LogicException('A tenant-bound report share is required.');
        }

        $reportType = $data['report_type'];
        $recipients = (array) ($data['recipients'] ?? []);
        $template   = $data['template'] ?? 'report_summary';
        $date       = $data['date'] ?? today()->toDateString();
        $branchId   = $data['branch_id'] ?? null;
        $summary    = $this->buildSummaryText($reportType, $date, $branchId);
        $queued     = 0;

        foreach ($recipients as $phone) {
            $phone = str_replace([' ', '-', '(', ')'], '', (string) $phone);
            if (blank($phone)) {
                continue;
            }

            SendWhatsAppMessageJob::dispatch(
                $phone,
                $template,
                [
                    'report_type' => self::SHAREABLE_REPORTS[$reportType] ?? $reportType,
                    'summary'     => $summary,
                    'date'        => $date,
                ],
                [
                    'audience' => 'report_share',
                    'report_type' => $reportType,
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                ]
            );

            $queued++;
        }

        return ['queued' => $queued, 'report_type' => $reportType, 'date' => $date];
    }

    private function buildSummaryText(string $reportType, string $date, ?int $branchId): string
    {
        $data = $this->analytics->getSalesAnalytics($date, $date, $branchId);

        return sprintf(
            'Date: %s | Revenue: %s | Orders: %d | AOV: %s',
            $date,
            number_format((float) ($data['net_sales'] ?? 0), 2),
            (int) ($data['total_orders'] ?? 0),
            number_format((float) ($data['avg_order_value'] ?? 0), 2)
        );
    }
}
