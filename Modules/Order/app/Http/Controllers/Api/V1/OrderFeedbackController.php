<?php

namespace Modules\Order\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cart\Support\PublicTenantGuard;
use Modules\Core\Http\Controllers\Controller;
use Modules\Order\Http\Requests\Api\V1\StoreOrderFeedbackRequest;
use Modules\User\Http\Concerns\ResolvesAppCustomer;
use Modules\Order\Services\OrderFeedback\OrderFeedbackServiceInterface;
use Modules\Order\Models\Order;
use Modules\Order\Support\OrderFeedbackAccess;
use Modules\Order\Transformers\Api\V1\OrderFeedbackResource;
use Modules\Support\ApiResponse;
use Modules\Support\GlobalStructureFilters;

class OrderFeedbackController extends Controller
{
    use ResolvesAppCustomer;
    public function __construct(protected OrderFeedbackServiceInterface $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: OrderFeedbackResource::class,
            filters: $request->get('with_filters') ? $this->filters() : null,
        );
    }

    public function stats(Request $request): JsonResponse
    {
        $stats = $this->service->stats($request->get('filters', []));

        return ApiResponse::success([
            ...$stats,
            'latest_negative' => OrderFeedbackResource::collection($stats['latest_negative']),
        ]);
    }

    public function store(StoreOrderFeedbackRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['tenant_id'] = PublicTenantGuard::tenantId($request);
        $customer = $request->user();
        if ($customer) {
            $data['customer_id'] = $this->customerForRequest($request)->id;
        } else {
            $order = Order::query()
                ->where('reference_no', $data['order_reference'])
                ->whereHas('branch', fn ($query) => $query->where('tenant_id', $data['tenant_id']))
                ->firstOrFail();
            abort_unless(OrderFeedbackAccess::valid($order, $data['feedback_token'] ?? null), 403, 'This feedback link is invalid.');
            $data['customer_id'] = $order->customer_id;
        }
        unset($data['feedback_token']);

        return ApiResponse::created(
            body: new OrderFeedbackResource($this->service->store($data)),
            resource: __('order::orders.feedback')
        );
    }

    private function filters(): array
    {
        return array_values(array_filter([
            GlobalStructureFilters::branch(),
            [
                'key' => 'rating',
                'label' => __('order::orders.feedback_filters.rating'),
                'type' => 'select',
                'options' => collect(range(1, 5))
                    ->map(fn(int $rating) => [
                        'id' => $rating,
                        'name' => __('order::orders.feedback_filters.rating_value', ['rating' => $rating]),
                    ])
                    ->values()
                    ->all(),
            ],
            [
                'key' => 'source',
                'label' => __('order::orders.feedback_filters.source'),
                'type' => 'select',
                'options' => collect(['web', 'whatsapp', 'qr', 'pos'])
                    ->map(fn(string $source) => [
                        'id' => $source,
                        'name' => __('order::orders.feedback_sources.'.$source),
                    ])
                    ->values()
                    ->all(),
            ],
            GlobalStructureFilters::from(__('order::orders.feedback_filters.from')),
            GlobalStructureFilters::to(__('order::orders.feedback_filters.to')),
        ]));
    }
}
