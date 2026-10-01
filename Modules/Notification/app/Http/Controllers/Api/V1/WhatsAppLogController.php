<?php

namespace Modules\Notification\Http\Controllers\Api\V1;

use App\NexDine;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Http\Controllers\Controller;
use Modules\Notification\Enums\NotificationStatus;
use Modules\Notification\Enums\WhatsAppProvider;
use Modules\Notification\Jobs\BulkSendWhatsAppMessageJob;
use Modules\Notification\Models\WhatsAppLog;
use Modules\Support\ApiResponse;
use Modules\Support\GlobalStructureFilters;

class WhatsAppLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$tenantId, $branchId] = $this->scope($request);

        return ApiResponse::pagination(
            paginator: WhatsAppLog::query()
                ->forTenant($tenantId, $branchId)
                ->filters($request->get('filters', []))
                ->latest()
                ->paginate(NexDine::paginate()),
            filters: $request->get('with_filters') ? $this->filters() : null,
        );
    }

    public function campaignSummary(Request $request): JsonResponse
    {
        [$tenantId, $branchId] = $this->scope($request);
        $campaignId = $request->string('campaign_id')->toString();
        $baseQuery = WhatsAppLog::query()
            ->forTenant($tenantId, $branchId)
            ->when(filled($campaignId), fn($query) => $query->where('request_payload->campaign_id', $campaignId))
            ->when(blank($campaignId), fn($query) => $query->whereNotNull('request_payload->campaign_id'));

        $statusCounts = (clone $baseQuery)
            ->select('status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $deliveryCounts = (clone $baseQuery)
            ->whereNotNull('delivery_status')
            ->select('delivery_status')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('delivery_status')
            ->pluck('total', 'delivery_status');

        $campaigns = WhatsAppLog::query()
            ->forTenant($tenantId, $branchId)
            ->whereNotNull('request_payload->campaign_id')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(request_payload, '$.campaign_id')) as campaign_id")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(request_payload, '$.audience')) as audience")
            ->selectRaw('template')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as sent", [NotificationStatus::Sent->value])
            ->selectRaw("SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed", [NotificationStatus::Failed->value])
            ->selectRaw("SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END) as delivered")
            ->selectRaw('MAX(created_at) as last_activity_at')
            ->groupBy('campaign_id', 'audience', 'template')
            ->latest('last_activity_at')
            ->limit(10)
            ->get()
            ->map(function ($campaign) {
                $total = max(1, (int) $campaign->total);

                return [
                    'campaign_id' => $campaign->campaign_id,
                    'audience' => $campaign->audience,
                    'audience_label' => $campaign->audience ? __("notification::notifications.audiences.{$campaign->audience}") : null,
                    'template' => $campaign->template,
                    'total' => (int) $campaign->total,
                    'sent' => (int) $campaign->sent,
                    'failed' => (int) $campaign->failed,
                    'delivered' => (int) $campaign->delivered,
                    'sent_rate' => round(((int) $campaign->sent / $total) * 100, 1),
                    'failed_rate' => round(((int) $campaign->failed / $total) * 100, 1),
                    'delivered_rate' => round(((int) $campaign->delivered / $total) * 100, 1),
                    'last_activity_at' => $campaign->last_activity_at ? dateTimeFormat(Carbon::parse($campaign->last_activity_at)) : null,
                ];
            });

        return ApiResponse::success([
            'totals' => [
                'total' => (clone $baseQuery)->count(),
                'processing' => (int) ($statusCounts[NotificationStatus::Processing->value] ?? 0),
                'sent' => (int) ($statusCounts[NotificationStatus::Sent->value] ?? 0),
                'failed' => (int) ($statusCounts[NotificationStatus::Failed->value] ?? 0),
                'delivered' => (int) ($deliveryCounts['delivered'] ?? 0),
                'read' => (int) ($deliveryCounts['read'] ?? 0),
                'delivery_failed' => (int) ($deliveryCounts['failed'] ?? 0),
            ],
            'campaigns' => $campaigns,
        ]);
    }

    private function scope(Request $request): array
    {
        $user = $request->user();
        abort_unless($user?->tenantId() !== null, 403, 'A restaurant tenant context is required.');

        return [$user->tenantId(), $user->branchId()];
    }

    private function filters(): array
    {
        return [
            [
                "key" => "campaign_id",
                "label" => __("notification::notifications.campaign_id"),
                "type" => "text",
            ],
            [
                "key" => "audience",
                "label" => __("notification::notifications.audience"),
                "type" => "select",
                "options" => BulkSendWhatsAppMessageJob::audienceOptions(),
            ],
            [
                "key" => "provider",
                "label" => __("notification::notifications.filters.provider"),
                "type" => "select",
                "options" => array_map(
                    fn(WhatsAppProvider $provider) => ["id" => $provider->value, "name" => $provider->trans()],
                    WhatsAppProvider::cases()
                ),
            ],
            [
                "key" => "status",
                "label" => __("notification::notifications.filters.status"),
                "type" => "select",
                "options" => $this->options(NotificationStatus::values()),
            ],
            [
                "key" => "delivery_status",
                "label" => __("notification::notifications.filters.delivery_status"),
                "type" => "select",
                "options" => [
                    ["id" => "delivered", "name" => __("notification::notifications.delivery_statuses.delivered")],
                    ["id" => "read", "name" => __("notification::notifications.delivery_statuses.read")],
                    ["id" => "failed", "name" => __("notification::notifications.delivery_statuses.failed")],
                ],
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    private function options(array $values): array
    {
        return array_map(fn(string $value) => [
            "id" => $value,
            "name" => __("notification::notifications.filters.options.{$value}"),
        ], $values);
    }
}
