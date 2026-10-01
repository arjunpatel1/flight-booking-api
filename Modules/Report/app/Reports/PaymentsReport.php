<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Enums\OrderSourceFilter;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Models\Payment;
use Modules\Payment\Services\PaymentAggregationService;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class PaymentsReport extends Report
{
    /** @inheritDoc */
    public function key(): string
    {
        return "payments";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "payment_method",
            "total_paid",
            "total"
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        $rate = $this->withRate ? 'currency_rate' : '1';

        return [
            "method",
            "MIN(created_at) as start_date",
            "MAX(created_at) as end_date",
            "COUNT(*) as total_paid",
            "SUM(amount * $rate) as total"
        ];
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Payment::class;
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "payment_method" => $model->method->trans(),
            "total_paid" => (int)$model->total_paid,
            "total" => new Money($model->total, $this->currency),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)->groupBy('method')
        ];
    }

    /** @inheritDoc */
    public function filters(Request $request): array
    {
        return [
            [
                "key" => 'method',
                "label" => __('report::reports.filters.payment_method'),
                "type" => 'select',
                "options" => PaymentMethod::toArrayTrans(),
            ],
            [
                "key" => 'status',
                "label" => __('report::reports.filters.order_status'),
                "type" => 'select',
                "options" => OrderStatus::toArrayTrans(),
            ],
            [
                "key" => 'type',
                "label" => __('report::reports.filters.order_type'),
                "type" => 'select',
                "options" => OrderType::toArrayTrans(),
            ],
            [
                "key" => 'source',
                "label" => __('report::reports.filters.order_source'),
                "type" => 'select',
                "options" => OrderSourceFilter::toArrayTrans(),
            ],
            [
                "key" => 'gateway',
                "label" => __('report::reports.filters.payment_channel'),
                "type" => 'select',
                "options" => [
                    ['id' => 'online', 'name' => 'All online payments'],
                    ['id' => 'razorpay', 'name' => 'Razorpay'],
                    ['id' => 'direct_upi', 'name' => 'Direct UPI'],
                ],
            ],
        ];
    }

    /** @inheritDoc */
    protected function summary(Request $request, array $data): array
    {
        $filters = (array) $request->input('filters', []);
        $breakdown = app(PaymentAggregationService::class)->summarize([
            'branch_id' => $filters['branch_id'] ?? null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
        ]);

        return [
            ...parent::summary($request, $data),
            $this->moneySummary('cash', __('payment::enums.payment_methods.cash'), $breakdown['cash'], 'tabler-cash', 'success'),
            $this->moneySummary('upi', __('payment::enums.payment_methods.upi'), $breakdown['upi'], 'tabler-qrcode', 'info'),
            $this->moneySummary('gross_collected', __('report::reports.gross_collected'), $breakdown['gross_collected'], 'tabler-receipt', 'primary'),
            $this->moneySummary('refunds', __('report::reports.refunds'), $breakdown['refunds'], 'tabler-receipt-refund', 'error'),
            $this->moneySummary('net_collected', __('report::reports.net_collected'), $breakdown['net_collected'], 'tabler-report-money', 'success'),
        ];
    }

    private function moneySummary(string $key, string $label, Money $value, string $icon, string $color): array
    {
        return compact('key', 'label', 'value', 'icon', 'color');
    }
}
