<?php

namespace Modules\WhatsAppCenter\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Modules\Notification\Enums\NotificationStatus;
use Modules\Notification\Models\WhatsAppLog;
use Modules\WhatsAppCenter\Models\WhatsAppSchedule;

class WhatsAppCenterService
{
    private int $cacheTtl = 300;

    public function getDashboard(?int $branchId = null): array
    {
        $cacheKey = makeCacheKey(['whatsapp_center', 'dashboard', $branchId ?? 'all', today()->toDateString()], false);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($branchId) {
            $today = today();
            $base = WhatsAppLog::query()
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->whereDate('created_at', $today);

            $todayTotal     = (clone $base)->count();
            $todaySent      = (clone $base)->where('status', NotificationStatus::Sent)->count();
            $todayFailed    = (clone $base)->where('status', NotificationStatus::Failed)->count();
            $todayPending   = (clone $base)->where('status', NotificationStatus::Processing)->count();
            $todayDelivered = (clone $base)->where('delivery_status', 'delivered')->count();

            $invoiceShared = (clone $base)
                ->where(fn($q) => $q->where('template', 'like', '%invoice%')
                    ->orWhere('template', 'like', '%bill%'))
                ->count();

            $reportsShared = (clone $base)
                ->where(fn($q) => $q->where('template', 'like', '%report%')
                    ->orWhere('template', 'like', '%summary%'))
                ->count();

            $uniqueRecipients = (clone $base)->distinct('recipient')->count('recipient');

            $trend = WhatsAppLog::query()
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->selectRaw('DATE(created_at) as date, COUNT(*) as total,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed',
                    [NotificationStatus::Sent->value, NotificationStatus::Failed->value])
                ->where('created_at', '>=', now()->subDays(7))
                ->groupByRaw('DATE(created_at)')
                ->orderByRaw('DATE(created_at)')
                ->get()
                ->map(fn($row) => [
                    'date'   => $row->date,
                    'total'  => (int) $row->total,
                    'sent'   => (int) $row->sent,
                    'failed' => (int) $row->failed,
                ]);

            $activeSchedules = $this->scheduleQuery($branchId)->where('is_active', true)->count();

            return [
                'today' => [
                    'total'          => $todayTotal,
                    'sent'           => $todaySent,
                    'failed'         => $todayFailed,
                    'pending'        => $todayPending,
                    'delivered'      => $todayDelivered,
                    'invoice_shared' => $invoiceShared,
                    'reports_shared' => $reportsShared,
                    'customers_reached' => $uniqueRecipients,
                ],
                'trend'            => $trend,
                'active_schedules' => $activeSchedules,
            ];
        });
    }

    public function getInsights(?int $branchId = null): array
    {
        $cacheKey = makeCacheKey(['whatsapp_center', 'insights', $branchId ?? 'all'], false);

        return Cache::remember($cacheKey, $this->cacheTtl * 2, function () use ($branchId) {
            $topMessaged = WhatsAppLog::query()
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->selectRaw('recipient, COUNT(*) as message_count,
                    SUM(CASE WHEN delivery_status = ? THEN 1 ELSE 0 END) as delivered_count,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed_count,
                    MAX(created_at) as last_messaged_at',
                    ['delivered', NotificationStatus::Failed->value])
                ->where('created_at', '>=', now()->subDays(30))
                ->groupBy('recipient')
                ->orderByDesc('message_count')
                ->limit(20)
                ->get()
                ->map(fn($row) => [
                    'recipient'       => $row->recipient,
                    'message_count'   => (int) $row->message_count,
                    'delivered_count' => (int) $row->delivered_count,
                    'failed_count'    => (int) $row->failed_count,
                    'delivery_rate'   => $row->message_count > 0
                        ? round(($row->delivered_count / $row->message_count) * 100, 1)
                        : 0,
                    'last_messaged_at' => $row->last_messaged_at
                        ? Carbon::parse($row->last_messaged_at)->diffForHumans()
                        : null,
                ]);

            $failedDeliveries = WhatsAppLog::query()
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->where('status', NotificationStatus::Failed)
                ->where('created_at', '>=', now()->subDays(7))
                ->select(['id', 'recipient', 'template', 'error_message', 'failed_attempts', 'created_at'])
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn($log) => [
                    'id'        => $log->id,
                    'recipient' => $log->recipient,
                    'template'  => $log->template,
                    'error'     => $log->error_message,
                    'attempts'  => $log->failed_attempts,
                    'failed_at' => $log->created_at?->toISOString(),
                ]);

            return [
                'top_messaged_customers'  => $topMessaged,
                'failed_deliveries_7d'    => $failedDeliveries,
                'delivery_rate_30d'       => $this->deliveryRate30d($branchId),
            ];
        });
    }

    private function deliveryRate30d(?int $branchId = null): float
    {
        $base = WhatsAppLog::query()
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('created_at', '>=', now()->subDays(30));
        $total     = (clone $base)->count();
        $delivered = (clone $base)->where('delivery_status', 'delivered')->count();

        return $total > 0 ? round(($delivered / $total) * 100, 1) : 0.0;
    }

    private function scheduleQuery(?int $branchId = null): Builder
    {
        $query = WhatsAppSchedule::query();
        if ($branchId) {
            return $query->where('branch_id', $branchId);
        }

        $tenantId = auth()->user()?->tenantId();
        if ($tenantId && ! auth()->user()?->isSuperAdmin()) {
            $query->whereIn('branch_id', fn ($branches) => $branches
                ->select('id')
                ->from('branches')
                ->where('tenant_id', $tenantId));
        }

        return $query;
    }
}
