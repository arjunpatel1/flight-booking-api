<?php

namespace Modules\Notification\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\NexDine;
use Modules\Core\Http\Controllers\Controller;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Enums\NotificationStatus;
use Modules\Notification\Models\NotificationLog;
use Modules\Support\ApiResponse;
use Modules\Support\GlobalStructureFilters;
use Modules\Notification\Transformers\Api\V1\NotificationLogResource;
use Modules\Saas\Support\TenantContext;

class NotificationLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id() ?? $request->user()?->tenantId();
        $isPlatformView = ! $tenantId && ($request->user()?->isSuperAdmin() ?? false);
        return ApiResponse::pagination(
            paginator: NotificationLog::query()
                ->when(! $isPlatformView, fn ($query) => $tenantId
                    ? $query->where('tenant_id', $tenantId)
                    : $query->whereRaw('1 = 0'))
                ->filters($request->get('filters', []))
                ->latest()
                ->paginate(NexDine::paginate()),
            resource: NotificationLogResource::class,
            filters: $request->get('with_filters') ? $this->filters() : null,
        );
    }

    private function filters(): array
    {
        return [
            [
                "key" => "channel",
                "label" => __("notification::notifications.filters.channel"),
                "type" => "select",
                "options" => $this->options(NotificationChannel::values()),
            ],
            [
                "key" => "status",
                "label" => __("notification::notifications.filters.status"),
                "type" => "select",
                "options" => $this->options(NotificationStatus::values()),
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
