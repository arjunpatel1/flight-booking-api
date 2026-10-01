<?php

namespace Modules\Aggregator\Http\Controllers\Api\V1;

use App\NexDine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Aggregator\Enums\AggregatorSyncStatus;
use Modules\Aggregator\Jobs\ProcessAggregatorWebhookEventJob;
use Modules\Aggregator\Models\AggregatorWebhookEvent;
use Modules\Aggregator\Services\Webhook\AggregatorWebhookServiceInterface;
use Modules\Aggregator\Transformers\Api\V1\AggregatorWebhookEventResource;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;

class AggregatorWebhookEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: AggregatorWebhookEvent::query()
                ->with(['integration:id,name'])
                ->filters($request->get('filters', []))
                ->sortBy($request->get('sorts', []))
                ->latest()
                ->paginate(NexDine::paginate())
                ->withQueryString(),
            resource: AggregatorWebhookEventResource::class
        );
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(new AggregatorWebhookEventResource(
            AggregatorWebhookEvent::query()->with(['integration:id,name'])->findOrFail($id)
        ));
    }

    public function receive(Request $request, AggregatorWebhookServiceInterface $service, int $integrationId): JsonResponse
    {
        $event = $service->store($integrationId, $request->headers->all(), $request->all());
        ProcessAggregatorWebhookEventJob::dispatch($event->id);

        return ApiResponse::success(message: __('admin::messages.resource_saved', ['resource' => __('aggregator::aggregator.webhook_event')]));
    }

    public function reprocess(int $id): JsonResponse
    {
        ProcessAggregatorWebhookEventJob::dispatch($id);

        return ApiResponse::success(message: __('admin::messages.resource_saved', ['resource' => __('aggregator::aggregator.webhook_event')]));
    }

    public function stats(): JsonResponse
    {
        $since = now()->subDay();

        return ApiResponse::success([
            'last_24h' => [
                'total' => AggregatorWebhookEvent::query()->where('created_at', '>=', $since)->count(),
                'pending' => AggregatorWebhookEvent::query()
                    ->where('created_at', '>=', $since)
                    ->whereIn('status', [
                        AggregatorSyncStatus::Pending->value,
                        AggregatorSyncStatus::Processing->value,
                        AggregatorSyncStatus::Retrying->value,
                    ])
                    ->count(),
                'processed' => AggregatorWebhookEvent::query()
                    ->where('created_at', '>=', $since)
                    ->whereIn('status', [
                        AggregatorSyncStatus::Success->value,
                        AggregatorSyncStatus::Ignored->value,
                        AggregatorSyncStatus::Skipped->value,
                    ])
                    ->count(),
                'failed' => AggregatorWebhookEvent::query()
                    ->where('created_at', '>=', $since)
                    ->where('status', AggregatorSyncStatus::Failed->value)
                    ->count(),
            ],
            'recent_events' => AggregatorWebhookEvent::query()
                ->select(['id', 'event_type', 'status', 'created_at'])
                ->with(['integration:id,name'])
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn($event) => [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'integration_name' => $event->integration?->name,
                    'status' => $event->status,
                    'created_at' => $event->created_at,
                ]),
        ]);
    }
}
