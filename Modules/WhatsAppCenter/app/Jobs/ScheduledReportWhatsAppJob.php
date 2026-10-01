<?php

namespace Modules\WhatsAppCenter\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\WhatsAppCenter\Models\WhatsAppSchedule;
use Modules\WhatsAppCenter\Services\WhatsAppReportShareService;
use Modules\Saas\Support\TenantContext;

class ScheduledReportWhatsAppJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $scheduleId,
        public readonly int $tenantId,
        public readonly int $branchId,
    )
    {
        $this->onQueue('whatsapp');
    }

    public function handle(WhatsAppReportShareService $service, TenantContext $context): void
    {
        $context->setId($this->tenantId);
        try {
            $schedule = WhatsAppSchedule::query()
                ->withoutGlobalScopes()
                ->whereKey($this->scheduleId)
                ->where('branch_id', $this->branchId)
                ->firstOrFail();

            if (! $schedule->is_active) {
                return;
            }

            $service->share([
                'report_type' => $schedule->report_type,
                'recipients'  => $schedule->recipients ?? [],
                'template'    => $schedule->template_name,
                'date'        => today()->toDateString(),
                'branch_id'   => $this->branchId,
                'tenant_id'   => $this->tenantId,
            ]);

            $schedule->update([
                'last_run_at' => now(),
                'next_run_at' => $schedule->computeNextRunAt(),
            ]);
        } finally {
            $context->clear();
        }
    }
}
