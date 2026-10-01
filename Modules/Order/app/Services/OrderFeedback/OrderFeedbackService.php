<?php

namespace Modules\Order\Services\OrderFeedback;

use App\NexDine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderFeedback;
use Modules\Order\Enums\OrderStatus;

class OrderFeedbackService implements OrderFeedbackServiceInterface
{
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->query($filters)
            ->with([
                'branch:id,name',
                'customer:id,name,phone',
                'order:id,reference_no,order_number,total,currency,customer_id,branch_id',
            ])
            ->filters($filters)
            ->sortBy($sorts)
            ->latest('submitted_at')
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    public function stats(array $filters = []): array
    {
        $query = $this->query($filters);
        $total = (clone $query)->count();
        $averageRating = (float) ((clone $query)->avg('rating') ?: 0);
        $positive = (clone $query)->where('rating', '>=', 4)->count();
        $negative = (clone $query)->where('rating', '<=', 2)->count();
        $neutral = max($total - $positive - $negative, 0);

        return [
            'total' => $total,
            'average_rating' => round($averageRating, 2),
            'positive' => $positive,
            'neutral' => $neutral,
            'negative' => $negative,
            'negative_rate' => $total > 0 ? round(($negative / $total) * 100, 2) : 0,
            'latest_negative' => (clone $query)
                ->with([
                    'branch:id,name',
                    'customer:id,name,phone',
                    'order:id,reference_no,order_number,total,currency,customer_id,branch_id',
                ])
                ->where('rating', '<=', 2)
                ->latest('submitted_at')
                ->limit(5)
                ->get(),
        ];
    }

    public function store(array $data): OrderFeedback
    {
        $order = Order::query()
            ->whereHas('branch', fn (Builder $query) => $query->where('tenant_id', $data['tenant_id']))
            ->where('reference_no', $data['order_reference'])
            ->where('customer_id', $data['customer_id'])
            ->whereIn('status', [OrderStatus::Completed, OrderStatus::Served])
            ->firstOrFail();

        return OrderFeedback::query()->updateOrCreate(
            ['order_id' => $order->id],
            [
                'branch_id' => $order->branch_id,
                'customer_id' => $order->customer_id,
                'rating' => $data['rating'],
                'tags' => array_values($data['tags'] ?? []),
                'comment' => $data['comment'] ?? null,
                'source' => $data['source'] ?? 'web',
                'submitted_at' => now(),
            ]
        );
    }

    private function query(array $filters = []): Builder
    {
        return OrderFeedback::query()
            ->filters($filters);
    }
}
