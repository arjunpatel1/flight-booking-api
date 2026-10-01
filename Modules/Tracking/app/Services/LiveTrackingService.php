<?php

namespace Modules\Tracking\Services;

use App\NexDine;
use DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderSourceFilter;
use Modules\Order\Enums\ReasonType;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Order\Models\Reason;
use Modules\Order\Support\OrderSourcePresenter;
use Modules\Support\GlobalStructureFilters;

class LiveTrackingService implements LiveTrackingServiceInterface
{
    public function activeOrders(array $filters = []): LengthAwarePaginator
    {
        return Order::query()
            ->withOutGlobalBranchPermission()
            ->with(["branch:id,name", "customer:id,name,phone,phone_country_iso_code", "aggregatorOrderMapping.integration:id,provider,name", "delivery"])
            ->activeOrders()
            ->filters($filters)
            ->latest()
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    public function show(int|string $id): array
    {
        $order = Order::query()
            ->withOutGlobalBranchPermission()
            ->with(["branch:id,name", "customer:id,name,phone,phone_country_iso_code", "statusLogs", "aggregatorOrderMapping.integration:id,provider,name", "delivery"])
            ->where(fn($query) => is_numeric($id)
                ? $query->where('id', $id)
                : $query->where('reference_no', $id))
            ->firstOrFail();

        $orderType = OrderType::coerce($order->type);
        $orderTypeData = $orderType->toTrans();

        return [
            "id" => $order->id,
            "reference_no" => $order->reference_no,
            "order_number" => $order->order_number,
            "type" => $orderTypeData['name'],
            "type_id" => $order->type->value,
            "order_type_data" => $orderTypeData,
            "current_status" => $order->status->toTrans(),
            "source" => OrderSourcePresenter::make($order),
            "estimated_delivery_at" => $order->scheduled_at ? dateTimeFormat($order->scheduled_at) : null,
            "rider" => null,
            "map" => [
                "provider" => setting('tracking_map_provider') ?: null,
                "coordinates" => null,
            ],
            "timeline" => $this->timeline($order),
        ];
    }

    public function getStructureFilters(): array
    {
        $branchFilter = GlobalStructureFilters::branch();

        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                "key" => "status",
                "label" => __("order::orders.filters.status"),
                "type" => "select",
                "options" => OrderStatus::toArrayTrans([
                    OrderStatus::Cancelled->value,
                    OrderStatus::Refunded->value,
                    OrderStatus::Merged->value,
                ]),
            ],
            [
                "key" => "type",
                "label" => __("order::orders.filters.type"),
                "type" => "select",
                "options" => OrderType::toArrayTrans(),
            ],
            [
                "key" => "source",
                "label" => __("order::orders.filters.source"),
                "type" => "select",
                "options" => OrderSourceFilter::toArrayTrans(),
            ],
            [
                "key" => "payment_status",
                "label" => __("order::orders.filters.payment_status"),
                "type" => "select",
                "options" => OrderPaymentStatus::toArrayTrans(),
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    public function pendingCount(): int
    {
        return Order::query()
            ->withOutGlobalBranchPermission()
            ->where('status', OrderStatus::Pending)
            ->count();
    }

    public function accept(int|string $id): array
    {
        $order = $this->findOrder($id);

        abort_unless($order->status === OrderStatus::Pending, 422, __('tracking::tracking.order_not_pending'));

        DB::transaction(function () use ($order) {
            $order->update(['status' => OrderStatus::Confirmed]);

            event(new OrderUpdateStatus(
                order: $order,
                status: OrderStatus::Confirmed,
                changedById: auth()->id(),
            ));
        });

        return $this->show($id);
    }

    public function reject(int|string $id, array $data): array
    {
        $order = $this->findOrder($id);

        abort_unless($order->status === OrderStatus::Pending, 422, __('tracking::tracking.order_not_pending'));

        DB::transaction(function () use ($order, $data) {
            $order->update(['status' => OrderStatus::Cancelled]);

            event(new OrderUpdateStatus(
                order: $order,
                status: OrderStatus::Cancelled,
                reasonId: $data['reason_id'],
                changedById: auth()->id(),
                note: $data['note'] ?? null,
            ));
        });

        return $this->show($id);
    }

    public function rejectMeta(): array
    {
        return [
            'reasons' => Reason::list(ReasonType::Cancellation->value),
        ];
    }

    private function timeline(Order $order): array
    {
        $flow = [
            OrderStatus::Pending,
            OrderStatus::Confirmed,
            OrderStatus::Preparing,
            OrderStatus::Ready,
            OrderStatus::Served,
            OrderStatus::Completed,
        ];

        $currentIndex = array_search($order->status, $flow, true);
        $currentIndex = $currentIndex === false ? -1 : $currentIndex;

        return collect($flow)->map(fn(OrderStatus $status, int $index) => [
            "status" => $status->toTrans(),
            "active" => $order->status === $status,
            "completed" => $index <= $currentIndex,
        ])->all();
    }

    private function findOrder(int|string $id): Order
    {
        return Order::query()
            ->withOutGlobalBranchPermission()
            ->where(fn($query) => is_numeric($id)
                ? $query->where('id', $id)
                : $query->where('reference_no', $id))
            ->firstOrFail();
    }
}
