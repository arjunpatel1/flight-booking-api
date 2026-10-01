<?php

namespace Modules\Dashboard\Services\Dashboard;

use Carbon\Carbon;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\Aggregator\Enums\AggregatorSyncStatus;
use Modules\Aggregator\Models\AggregatorIntegration;
use Modules\Aggregator\Models\AggregatorOrderMapping;
use Modules\Aggregator\Models\AggregatorSyncLog;
use Modules\Aggregator\Models\AggregatorWebhookEvent;
use Modules\Category\Models\Category;
use Modules\Currency\Currency;
use Modules\Dashboard\Enums\AnalyticsPeriod;
use Modules\Dashboard\Enums\SalesAnalyticsFilter;
use Modules\Dashboard\Models\AiInsightSnapshot;
use Modules\Inventory\Models\Ingredient;
use Modules\Menu\Models\Menu;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderProduct;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Models\Payment;
use Modules\Payment\Services\PaymentAggregationService;
use Modules\Pos\Models\PosCashMovement;
use Modules\Product\Models\Product;
use Modules\Support\Enums\Day;
use Modules\Support\Enums\Monthly;
use Modules\Support\Money;
use Modules\Support\RTLDetector;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class DashboardService implements DashboardServiceInterface
{
    /** {@inheritDoc} */
    public function overview(AnalyticsPeriod $period = AnalyticsPeriod::Today): array
    {
        $user = auth()->user();
        $currency = $user->assignedToBranch() ? $user->branch->currency : setting('default_currency');
        [$start, $end] = $period->range();

        $paymentBreakdown = app(PaymentAggregationService::class)->summarize(array_filter([
            'from' => $start?->toDateString(),
            'to' => $end?->toDateString(),
        ]));

        $collectionBase = Payment::query()
            ->whereIn('status', [\Modules\Payment\Enums\PaymentStatus::Completed->value, \Modules\Payment\Enums\PaymentStatus::Refunded->value])
            ->when($start, fn ($query) => $query->whereRaw('DATE(COALESCE(received_at, processed_at, created_at)) >= ?', [$start->toDateString()]))
            ->when($end, fn ($query) => $query->whereRaw('DATE(COALESCE(received_at, processed_at, created_at)) <= ?', [$end->toDateString()]));

        $collectionAmount = fn (Builder $query) => Money::inDefaultCurrency(
            (float) $query->selectRaw("SUM(CASE WHEN status = 'refunded' THEN -amount * COALESCE(currency_rate, 1) ELSE amount * COALESCE(currency_rate, 1) END) AS aggregate")
                ->value('aggregate')
        )->format();

        return [
            'daily_sales' => $this->callIfAuthorized(
                'admin.dashboards.total_sales',
                fn () => $this->dailySales($start, $end, $currency)->format()
            ),
            'daily_sales_amount' => $this->callIfAuthorized(
                'admin.dashboards.total_sales',
                fn () => $this->dailySales($start, $end, $currency)->amount()
            ),
            'daily_orders' => $this->callIfAuthorized(
                'admin.dashboards.total_orders',
                fn () => Order::withoutCanceledOrders()
                    ->when($start, fn ($query) => $query->whereBetween('created_at', [$start, $end]))
                    ->count()
            ),
            // This card belongs to the period-filtered overview. Apply the
            // same range as the other overview KPIs; the separate operational
            // metrics endpoint remains the all-dates live snapshot.
            'total_active_orders' => $this->callIfAuthorized(
                'admin.dashboards.total_active_orders',
                fn () => Order::paymentPendingActiveOrders()
                    ->when($start, fn ($query) => $query->whereBetween('created_at', [$start, $end]))
                    ->count()
            ),
            'average_order_value' => $this->callIfAuthorized(
                'admin.dashboards.average_order_value',
                fn () => $this->periodAverageOrderValue($start, $end, $currency)->format()
            ),
            'average_order_value_amount' => $this->callIfAuthorized(
                'admin.dashboards.average_order_value',
                fn () => $this->periodAverageOrderValue($start, $end, $currency)->amount()
            ),
            'currency' => $currency,
            'payment_breakdown' => $paymentBreakdown,
            'collection_channels' => [
                'waiter' => $this->callIfAuthorized(
                    'admin.dashboards.payments_overview',
                    fn () => $collectionAmount((clone $collectionBase)->whereHas('order', fn ($query) => $query->whereNotNull('waiter_id')))
                ),
                'customer' => $this->callIfAuthorized(
                    'admin.dashboards.payments_overview',
                    fn () => $collectionAmount((clone $collectionBase)->whereHas('order', fn ($query) => $query->whereNotNull('customer_id')->whereNull('waiter_id')))
                ),
            ],
            'total_users' => $this->callIfAuthorized(
                'admin.dashboards.total_users',
                fn () => User::withoutGlobalActive()->count()
            ),
            'total_menus' => $this->callIfAuthorized(
                'admin.dashboards.total_menus',
                fn () => Menu::withoutGlobalActive()->count()
            ),
            'total_products' => $this->callIfAuthorized(
                'admin.dashboards.total_products',
                fn () => Product::whereHas('menu', fn ($query) => $query->where('is_active', true))
                    ->withoutGlobalActive()
                    ->count()
            ),
            'total_categories' => $this->callIfAuthorized(
                'admin.dashboards.total_categories',
                fn () => Category::whereHas('menu', fn ($query) => $query->where('is_active', true))
                    ->withoutGlobalActive()
                    ->count()
            ),
            'recent_users' => $this->callIfAuthorized(
                'admin.dashboards.total_users',
                fn () => User::withoutGlobalActive()
                    ->latest('created_at')
                    ->limit(6)
                    ->get(['id', 'uuid', 'name', 'is_active', 'created_at'])
                    ->map(fn (User $recentUser) => [
                        'uuid' => $recentUser->uuid,
                        'name' => $recentUser->name,
                        'role' => $recentUser->roles->first()?->name,
                        'is_active' => (bool) $recentUser->is_active,
                        'created_at' => $recentUser->created_at?->toIso8601String(),
                    ])->values()->all()
            ),
            // Resolved range for the selected period so time-based cards can deep-link
            // to the orders list pre-filtered to exactly what was counted.
            'period' => [
                'key' => $period->value,
                'from' => $start?->toDateString(),
                'to' => $end?->toDateString(),
            ],
        ];
    }

    private function dailySales(?Carbon $start, ?Carbon $end, string $currency): Money
    {
        $applyRange = fn ($query) => $query
            ->when($start, fn ($q) => $q->whereBetween('created_at', [$start, $end]));

        if (auth()->check() && auth()->user()->assignedToBranch()) {
            $total = $applyRange(Order::withoutCanceledOrders())->sum('total');
        } else {
            $total = $applyRange(Order::withoutCanceledOrders())
                ->selectRaw('SUM(total * COALESCE(currency_rate, 1)) AS total_sales')
                ->value('total_sales') ?? 0;
        }

        return new Money($total, $currency);
    }

    /**
     * Average order value for the selected period. Kept in the service (rather
     * than the Order scope) so the period range can be applied consistently
     * with dailySales, including cross-currency conversion for tenant admins.
     */
    private function periodAverageOrderValue(?Carbon $start, ?Carbon $end, string $currency): Money
    {
        $base = Order::withoutCanceledOrders()
            ->when($start, fn ($query) => $query->whereBetween('created_at', [$start, $end]));

        $count = (clone $base)->count();

        if ($count === 0) {
            return new Money(0, $currency);
        }

        $total = auth()->check() && auth()->user()->assignedToBranch()
            ? (float) (clone $base)->sum('total')
            : (float) ((clone $base)->selectRaw('SUM(total * COALESCE(currency_rate, 1)) AS total_sales')
                ->value('total_sales') ?? 0);

        return new Money($total / $count, $currency);
    }

    /** {@inheritDoc} */
    public function operationalMetrics(): array
    {
        $currency = auth()->user()->assignedToBranch()
            ? auth()->user()->branch->currency
            : setting('default_currency');

        $platformSales = $this->platformSales($currency);
        $successLogs = AggregatorSyncLog::where('status', AggregatorSyncStatus::Success->value)->count();
        $failedLogs = AggregatorSyncLog::where('status', AggregatorSyncStatus::Failed->value)->count();
        $totalLogs = $successLogs + $failedLogs;

        return [
            'live_orders' => [
                'active_orders' => $this->callIfAuthorized(
                    'admin.dashboards.live',
                    fn () => Order::paymentPendingActiveOrders()->count()
                ),
                'pending_aggregator_orders' => auth()->user()->can('admin.dashboards.live')
                    ? AggregatorSyncLog::whereIn('status', [
                        AggregatorSyncStatus::Pending->value,
                        AggregatorSyncStatus::Processing->value,
                        AggregatorSyncStatus::Retrying->value,
                    ])->count()
                    : 0,
                'failed_sync_count' => auth()->user()->can('admin.dashboards.live')
                    ? $failedLogs
                    : 0,
                'processing_queue_count' => auth()->user()->can('admin.dashboards.live')
                    ? $this->queueBacklog(['aggregator-sync', 'aggregator-webhooks', 'aggregator-menu', 'aggregator-status'])
                    : 0,
                'delivery_in_progress' => auth()->user()->can('admin.dashboards.live')
                    ? Order::whereIn('status', [OrderStatus::Ready->value, OrderStatus::Served->value])->count()
                    : 0,
            ],
            'aggregator' => $this->callIfAuthorized(
                'admin.dashboards.analytics',
                fn () => [
                    'platform_sales' => $this->platformSales($currency),
                    'top_selling_platform' => collect($platformSales)
                        ->sortByDesc('amount')
                        ->first()['label'] ?? null,
                ]
            ),
            'operations' => $this->callIfAuthorized(
                'admin.dashboards.live',
                fn () => [
                    'failed_webhooks' => AggregatorWebhookEvent::where('status', AggregatorSyncStatus::Failed->value)->count(),
                    'retry_queue_count' => AggregatorSyncLog::where('status', AggregatorSyncStatus::Retrying->value)->count(),
                    'sync_success_percentage' => $totalLogs > 0 ? round(($successLogs / $totalLogs) * 100, 2) : 100,
                    'provider_health' => AggregatorIntegration::query()
                        ->get(['id', 'provider', 'name', 'is_active'])
                        ->map(fn (AggregatorIntegration $integration) => [
                            'id' => $integration->id,
                            'provider' => $integration->provider->value,
                            'label' => $integration->name,
                            'status' => $integration->is_active ? 'online' : 'paused',
                        ])
                        ->values(),
                ]
            ),
        ];
    }

    public function monitoring(): array
    {
        $queues = [
            'notifications',
            'whatsapp',
            'live-orders',
            'tracking',
            'aggregator-sync',
            'aggregator-webhooks',
            'emails',
            'analytics',
        ];

        return [
            'queues' => collect($queues)->map(fn (string $queue) => [
                'name' => $queue,
                'pending' => $this->queueBacklog([$queue]),
            ])->values(),
            'failed_jobs' => [
                'total' => $this->failedJobsCount(),
                'latest' => $this->latestFailedJobs(),
            ],
            'health' => [
                'jobs_table' => Schema::hasTable('jobs'),
                'failed_jobs_table' => Schema::hasTable('failed_jobs'),
                'database_queue' => config('queue.default') === 'database',
                'queue_connection' => config('queue.default'),
            ],
            'readiness' => $this->marketReadiness(),
        ];
    }

    private function marketReadiness(): array
    {
        $items = [
            $this->readinessItem('pos', [
                'pos_registers',
                'pos_sessions',
                'orders',
                'order_products',
                'payments',
                'invoices',
            ]),
            $this->readinessItem('kds', [
                'kitchen_stations',
                'kitchen_station_categories',
                'kitchen_station_order_products',
            ]),
            $this->readinessItem('inventory', [
                'ingredients',
                'ingredientables',
                'stock_movements',
                'purchases',
                'purchase_receipts',
            ]),
            $this->readinessItem('qr_ordering', [
                'online_menus',
                'carts',
                'table_reservations',
            ]),
            $this->readinessItem('aggregators', [
                'aggregator_integrations',
                'aggregator_outlet_mappings',
                'aggregator_menu_mappings',
                'aggregator_order_mappings',
                'aggregator_sync_logs',
                'aggregator_webhook_events',
            ]),
            $this->readinessItem('crm_marketing', [
                'loyalty_programs',
                'loyalty_customers',
                'loyalty_promotions',
                'vouchers',
                'gift_cards',
                'whats_app_logs',
                'notifications',
            ]),
            $this->readinessItem('employee', [
                'employee_shifts',
                'employee_attendances',
                'employee_compensations',
                'employee_payroll_runs',
                'employee_payslips',
            ]),
            $this->readinessItem('hardware', [
                'printers',
                'print_agents',
                'print_jobs',
            ]),
            $this->readinessItem('saas', [
                'tenants',
                'subscription_plans',
                'tenant_subscriptions',
                'tenant_feature_limits',
            ]),
            $this->readinessItem('reliability', [
                'jobs',
                'failed_jobs',
                'system_backups',
                'system_restores',
                'authentication_log',
            ]),
        ];

        $score = collect($items)->avg('score') ?: 0;

        return [
            'score' => round($score, 2),
            'status' => $score >= 85 ? 'strong' : ($score >= 60 ? 'attention' : 'weak'),
            'items' => $items,
        ];
    }

    private function readinessItem(string $key, array $tables): array
    {
        $available = collect($tables)
            ->filter(fn (string $table) => Schema::hasTable($table))
            ->values();

        $configured = $available
            ->filter(fn (string $table) => $this->tableCount($table) > 0)
            ->values();

        $structureScore = count($tables) > 0 ? ($available->count() / count($tables)) * 60 : 0;
        $dataScore = count($tables) > 0 ? ($configured->count() / count($tables)) * 40 : 0;
        $score = min(100, round($structureScore + $dataScore, 2));

        return [
            'key' => $key,
            'score' => $score,
            'status' => $score >= 85 ? 'ready' : ($score >= 60 ? 'needs_data' : 'missing'),
            'available_tables' => $available->all(),
            'missing_tables' => collect($tables)->diff($available)->values()->all(),
            'configured_tables' => $configured->all(),
        ];
    }

    private function tableCount(string $table): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        try {
            return (int) DB::table($table)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function smartInsights(): array
    {
        if (! setting('dashboard_smart_insights_enabled', true)) {
            return [
                'enabled' => false,
                'window_days' => 0,
                'summary' => [],
                'recommendations' => [],
                'top_products' => [],
                'low_movers' => [],
                'peak_hours' => [],
                'margin_watch' => [],
                'demand_forecast' => [],
            ];
        }

        $days = min(max((int) setting('dashboard_smart_insights_window_days', 30), 7), 365);
        $limit = min(max((int) setting('dashboard_smart_insights_limit', 5), 3), 20);
        $currency = auth()->user()->assignedToBranch()
            ? auth()->user()->branch->currency
            : setting('default_currency');
        $withRate = ! auth()->user()->assignedToBranch();
        $start = now()->subDays($days - 1)->startOfDay();
        $end = now()->endOfDay();
        $previousStart = $start->copy()->subDays($days);
        $previousEnd = $start->copy()->subSecond();

        $current = $this->periodOrderSummary($start, $end, $withRate);
        $previous = $this->periodOrderSummary($previousStart, $previousEnd, $withRate);
        $topProducts = $this->periodProductSales($start, $end, $limit, $withRate);
        $lowMovers = $this->lowMovingProducts($start, $end, $limit);
        $peakHours = $this->peakSalesHours($start, $end, $limit, $withRate);
        $marginWatch = $this->marginWatchProducts($start, $end, $limit, $withRate);
        $change = $previous['sales'] > 0
            ? round((($current['sales'] - $previous['sales']) / $previous['sales']) * 100, 2)
            : null;

        return [
            'enabled' => true,
            'window_days' => $days,
            'currency' => $currency,
            'summary' => [
                'orders' => (int) $current['orders'],
                'sales' => (new Money($current['sales'], $currency))->format(),
                'sales_amount' => round($current['sales'], Currency::subunit($currency)),
                'average_order_value' => (new Money($current['average_order_value'], $currency))->format(),
                'change_percentage' => $change,
            ],
            'recommendations' => $this->buildSmartRecommendations(
                change: $change,
                current: $current,
                topProducts: $topProducts,
                lowMovers: $lowMovers,
                peakHours: $peakHours,
                marginWatch: $marginWatch
            ),
            'top_products' => $topProducts,
            'low_movers' => $lowMovers,
            'peak_hours' => $peakHours,
            'margin_watch' => $marginWatch,
            'demand_forecast' => $this->demandForecast(),
        ];
    }

    public function demandForecast(): array
    {
        if (! setting('dashboard_smart_insights_enabled', true)) {
            return [
                'enabled' => false,
                'days' => 0,
                'items' => [],
                'summary' => [],
            ];
        }

        $historyDays = min(max((int) setting('dashboard_demand_forecast_history_days', 56), 14), 365);
        $forecastDays = min(max((int) setting('dashboard_demand_forecast_days', 7), 1), 31);
        $currency = auth()->user()->assignedToBranch()
            ? auth()->user()->branch->currency
            : setting('default_currency');
        $withRate = ! auth()->user()->assignedToBranch();
        $start = now()->subDays($historyDays)->startOfDay();
        $end = now()->subDay()->endOfDay();

        $history = Order::query()
            ->withoutCanceledOrders()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as order_date')
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw($withRate
                ? 'COALESCE(SUM(total * COALESCE(currency_rate, 1)), 0) as total_sales'
                : 'COALESCE(SUM(total), 0) as total_sales')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('order_date')
            ->get()
            ->map(fn ($row) => [
                'date' => Carbon::parse($row->order_date),
                'orders' => (float) $row->total_orders,
                'sales' => (float) $row->total_sales,
            ]);

        $averageOrderValue = $history->sum('orders') > 0
            ? $history->sum('sales') / $history->sum('orders')
            : 0;
        $recentAverageOrders = $history
            ->sortByDesc(fn ($row) => $row['date']->timestamp)
            ->take(min(7, max(1, $forecastDays)))
            ->avg('orders') ?? 0;

        $items = collect(range(1, $forecastDays))
            ->map(function (int $offset) use ($history, $recentAverageOrders, $averageOrderValue, $currency) {
                $date = now()->addDays($offset)->startOfDay();
                $weekdaySamples = $history->filter(fn ($row) => $row['date']->dayOfWeek === $date->dayOfWeek);
                $weekdayAverageOrders = $weekdaySamples->avg('orders') ?? 0;
                $forecastOrders = ($weekdayAverageOrders * 0.7) + ($recentAverageOrders * 0.3);
                $sampleCount = $weekdaySamples->count();

                return [
                    'date' => $date->toDateString(),
                    'label' => $date->translatedFormat('D, M d'),
                    'weekday' => $date->translatedFormat('l'),
                    'forecast_orders' => (int) round($forecastOrders),
                    'forecast_sales' => (new Money($forecastOrders * $averageOrderValue, $currency))->format(),
                    'forecast_sales_amount' => round($forecastOrders * $averageOrderValue, Currency::subunit($currency)),
                    'confidence' => match (true) {
                        $sampleCount >= 6 => 'high',
                        $sampleCount >= 3 => 'medium',
                        default => 'low',
                    },
                    'sample_count' => $sampleCount,
                ];
            })
            ->values();

        $peakDay = $items->sortByDesc('forecast_orders')->first();

        return [
            'enabled' => true,
            'history_days' => $historyDays,
            'days' => $forecastDays,
            'currency' => $currency,
            'summary' => [
                'total_forecast_orders' => (int) $items->sum('forecast_orders'),
                'total_forecast_sales' => (new Money($items->sum('forecast_sales_amount'), $currency))->format(),
                'peak_day' => $peakDay,
            ],
            'items' => $items->all(),
        ];
    }

    public function storeSmartInsightSnapshot(?int $generatedBy = null): array
    {
        $insights = $this->smartInsights();

        if (($insights['enabled'] ?? false) === false) {
            return $insights;
        }

        AiInsightSnapshot::query()->create([
            'window_days' => $insights['window_days'],
            'currency' => $insights['currency'] ?? null,
            'summary' => $insights['summary'] ?? [],
            'recommendations' => $insights['recommendations'] ?? [],
            'payload' => $insights,
            'generated_at' => now(),
            'generated_by' => $generatedBy,
        ]);

        return $insights;
    }

    public function smartInsightSnapshots(): mixed
    {
        return AiInsightSnapshot::query()
            ->with('generator:id,name')
            ->latest('generated_at')
            ->paginate(10)
            ->withQueryString();
    }

    public function assistant(string $prompt): array
    {
        $responseMessage = $this->fetchOpenAiResponse($prompt);

        return [
            'message' => $responseMessage,
        ];
    }

    private function fetchOpenAiResponse(string $prompt): string
    {
        if (! setting('dashboard_ai_assistant_enabled', false)) {
            return __('pos::pos.ai.placeholder_response');
        }

        $apiKey = config('services.openai.key');
        $apiBaseUrl = rtrim((string) setting('dashboard_ai_base_url', config('services.openai.base_uri', 'https://api.openai.com/v1')), '/');
        $model = setting('dashboard_ai_model', config('services.openai.model', 'gpt-4o-mini'));
        $systemPrompt = setting('dashboard_ai_system_prompt', __('dashboard::dashboards.ai.default_system_prompt'));
        $temperature = (float) setting('dashboard_ai_temperature', 0.4);
        $maxTokens = (int) setting('dashboard_ai_max_tokens', 250);

        if (empty($apiKey)) {
            return __('pos::pos.ai.placeholder_response');
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->post("{$apiBaseUrl}/chat/completions", [
                    'model' => config('services.openai.model', 'gpt-3.5-turbo'),
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt,
                        ],
                        [
                            'role' => 'user',
                            'content' => trim($prompt),
                        ],
                    ],
                    'temperature' => $temperature,
                    'max_tokens' => $maxTokens,
                ]);

            if (! $response->successful()) {
                return __('pos::pos.ai.placeholder_response');
            }

            $message = data_get($response->json(), 'choices.0.message.content');

            if (empty($message)) {
                return __('pos::pos.ai.placeholder_response');
            }

            return trim($message);
        } catch (\Exception $exception) {
            return __('pos::pos.ai.placeholder_response');
        }
    }

    /**
     * Execute a callback only if the authenticated user has the given permission.
     *
     * @return mixed|null
     */
    private function callIfAuthorized(string $permission, callable $callback): mixed
    {
        return auth()->user()->can($permission) ? $callback() : null;
    }

    private function queueBacklog(array $queues): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        return DB::table('jobs')->whereIn('queue', $queues)->count();
    }

    private function failedJobsCount(): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        return DB::table('failed_jobs')->count();
    }

    private function latestFailedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        return DB::table('failed_jobs')
            ->latest('failed_at')
            ->limit(10)
            ->get(['id', 'uuid', 'connection', 'queue', 'exception', 'failed_at'])
            ->map(fn ($job) => [
                'id' => $job->id,
                'uuid' => $job->uuid,
                'connection' => $job->connection,
                'queue' => $job->queue,
                'exception' => str($job->exception)->limit(180)->toString(),
                'failed_at' => $job->failed_at,
            ])
            ->all();
    }

    private function periodOrderSummary(Carbon $start, Carbon $end, bool $withRate): array
    {
        $row = Order::query()
            ->withoutCanceledOrders()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw($withRate
                ? 'COUNT(*) as total_orders, COALESCE(SUM(total * COALESCE(currency_rate, 1)), 0) as total_sales'
                : 'COUNT(*) as total_orders, COALESCE(SUM(total), 0) as total_sales')
            ->first();

        $sales = (float) ($row?->total_sales ?? 0);
        $orders = (int) ($row?->total_orders ?? 0);

        return [
            'orders' => $orders,
            'sales' => $sales,
            'average_order_value' => $orders > 0 ? $sales / $orders : 0,
        ];
    }

    private function periodProductSales(Carbon $start, Carbon $end, int $limit, bool $withRate): array
    {
        return OrderProduct::query()
            ->without(['product', 'taxes', 'options'])
            ->whereHas('order', fn (Builder $query) => $query
                ->withoutCanceledOrders()
                ->whereBetween('created_at', [$start, $end]))
            ->with('product', fn ($query) => $query->with('files'))
            ->select('product_id', 'currency')
            ->selectRaw('SUM(quantity) as total_quantity')
            ->selectRaw($withRate
                ? 'SUM(total * COALESCE(currency_rate, 1)) as total_sales'
                : 'SUM(total) as total_sales')
            ->groupBy('product_id', 'currency')
            ->orderByDesc('total_quantity')
            ->limit($limit)
            ->get()
            ->map(fn (OrderProduct $item) => [
                'id' => $item->product_id,
                'name' => $item->product?->name ?? '-',
                'thumbnail' => $item->product?->thumbnail != null ? $item->product->thumbnail->preview_image_url : null,
                'total_quantity' => (int) $item->total_quantity,
                'total_sales' => $withRate
                    ? Money::inDefaultCurrency((float) $item->total_sales)
                    : new Money((float) $item->total_sales, $item->currency),
            ])
            ->values()
            ->all();
    }

    private function lowMovingProducts(Carbon $start, Carbon $end, int $limit): array
    {
        return Product::query()
            ->whereHas('menu', fn ($query) => $query->where('is_active', true))
            ->withSum(['orderProducts as sold_quantity' => fn (Builder $query) => $query
                ->whereHas('order', fn (Builder $orderQuery) => $orderQuery
                    ->withoutCanceledOrders()
                    ->whereBetween('created_at', [$start, $end]))], 'quantity')
            ->orderBy('sold_quantity')
            ->orderBy('display_priority')
            ->limit($limit)
            ->get(['id', 'name', 'price', 'menu_id'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sold_quantity' => (int) $product->sold_quantity,
                'price' => $product->price->format(),
            ])
            ->values()
            ->all();
    }

    private function peakSalesHours(Carbon $start, Carbon $end, int $limit, bool $withRate): array
    {
        return Order::query()
            ->withoutCanceledOrders()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('HOUR(created_at) as hour')
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw($withRate
                ? 'SUM(total * COALESCE(currency_rate, 1)) as total_sales'
                : 'SUM(total) as total_sales')
            ->groupByRaw('HOUR(created_at)')
            ->orderByDesc('total_orders')
            ->limit($limit)
            ->get()
            ->map(fn (Order $order) => [
                'hour' => (int) $order->hour,
                'label' => Carbon::parse(str_pad((string) $order->hour, 2, '0', STR_PAD_LEFT).':00')
                    ->format(setting('default_time_format')),
                'total_orders' => (int) $order->total_orders,
                'total_sales' => (float) $order->total_sales,
            ])
            ->values()
            ->all();
    }

    private function marginWatchProducts(Carbon $start, Carbon $end, int $limit, bool $withRate): array
    {
        return OrderProduct::query()
            ->without(['product', 'taxes', 'options'])
            ->whereHas('order', fn (Builder $query) => $query
                ->withoutCanceledOrders()
                ->whereBetween('created_at', [$start, $end]))
            ->with('product:id,name,menu_id')
            ->select('product_id', 'currency')
            ->selectRaw($withRate
                ? 'SUM(total * COALESCE(currency_rate, 1)) as total_sales'
                : 'SUM(total) as total_sales')
            ->selectRaw($withRate
                ? 'SUM((CAST(total AS DECIMAL(20,4)) - CAST(cost_price AS DECIMAL(20,4))) * COALESCE(currency_rate, 1)) as gross_profit'
                : 'SUM(CAST(total AS DECIMAL(20,4)) - CAST(cost_price AS DECIMAL(20,4))) as gross_profit')
            ->groupBy('product_id', 'currency')
            ->havingRaw('SUM(total) > 0')
            ->get()
            ->map(function (OrderProduct $item) {
                $sales = (float) $item->total_sales;
                $profit = (float) $item->gross_profit;

                return [
                    'id' => $item->product_id,
                    'name' => $item->product?->name ?? '-',
                    'margin_percentage' => $sales > 0 ? round(($profit / $sales) * 100, 2) : 0,
                ];
            })
            ->sortBy('margin_percentage')
            ->take($limit)
            ->values()
            ->all();
    }

    private function buildSmartRecommendations(
        ?float $change,
        array $current,
        array $topProducts,
        array $lowMovers,
        array $peakHours,
        array $marginWatch
    ): array {
        $recommendations = [];

        if ($change !== null) {
            $recommendations[] = [
                'code' => $change >= 0 ? 'revenue_up' : 'revenue_down',
                'severity' => $change >= 0 ? 'success' : 'warning',
                'icon' => $change >= 0 ? 'tabler-trending-up' : 'tabler-trending-down',
                'context' => ['value' => abs($change)],
            ];
        }

        if (! empty($topProducts)) {
            $recommendations[] = [
                'code' => 'promote_best_seller',
                'severity' => 'info',
                'icon' => 'tabler-award',
                'context' => [
                    'product' => $topProducts[0]['name'],
                    'quantity' => $topProducts[0]['total_quantity'],
                ],
            ];
        }

        if (! empty($lowMovers) && (int) $lowMovers[0]['sold_quantity'] === 0) {
            $recommendations[] = [
                'code' => 'review_low_mover',
                'severity' => 'warning',
                'icon' => 'tabler-bulb',
                'context' => ['product' => $lowMovers[0]['name']],
            ];
        }

        if (! empty($peakHours)) {
            $recommendations[] = [
                'code' => 'staff_peak_hour',
                'severity' => 'info',
                'icon' => 'tabler-clock-hour-4',
                'context' => [
                    'hour' => $peakHours[0]['label'],
                    'orders' => $peakHours[0]['total_orders'],
                ],
            ];
        }

        if (! empty($marginWatch) && (float) $marginWatch[0]['margin_percentage'] < 20) {
            $recommendations[] = [
                'code' => 'margin_watch',
                'severity' => 'error',
                'icon' => 'tabler-cash-banknote-off',
                'context' => [
                    'product' => $marginWatch[0]['name'],
                    'margin' => $marginWatch[0]['margin_percentage'],
                ],
            ];
        }

        if ($current['orders'] === 0) {
            $recommendations[] = [
                'code' => 'no_orders',
                'severity' => 'warning',
                'icon' => 'tabler-alert-triangle',
                'context' => [],
            ];
        }

        return $recommendations;
    }

    private function platformSales(string $currency): array
    {
        $precision = Currency::subunit($currency);
        $aggregatorTotals = AggregatorOrderMapping::query()
            ->join('orders', 'orders.id', '=', 'aggregator_order_mappings.order_id')
            ->join('aggregator_integrations', 'aggregator_integrations.id', '=', 'aggregator_order_mappings.aggregator_integration_id')
            ->whereNotIn('orders.status', [
                OrderStatus::Cancelled->value,
                OrderStatus::Refunded->value,
                OrderStatus::Merged->value,
            ])
            ->selectRaw('aggregator_integrations.provider, SUM(orders.total * COALESCE(orders.currency_rate, 1)) as total_sales')
            ->groupBy('aggregator_integrations.provider')
            ->pluck('total_sales', 'provider');

        $directSales = Order::query()
            ->withoutCanceledOrders()
            ->whereDoesntHave('aggregatorOrderMapping')
            ->selectRaw('SUM(total * COALESCE(currency_rate, 1)) as total_sales')
            ->value('total_sales') ?? 0;

        $providerValues = AggregatorIntegration::query()
            ->select('provider')
            ->distinct()
            ->pluck('provider')
            ->map(fn ($provider) => $provider->value)
            ->merge($aggregatorTotals->keys()->map(fn ($provider) => $provider->value))
            ->filter()
            ->unique()
            ->values();

        return collect($providerValues)
            ->map(fn ($provider) => [
                'key' => (string) $provider,
                'label' => str((string) $provider)->replace(['-', '_'], ' ')->headline()->toString(),
                'amount' => round((float) ($aggregatorTotals[(string) $provider] ?? 0), $precision),
            ])
            ->push([
                'key' => 'direct',
                'label' => __('order::orders.sources.direct'),
                'amount' => round((float) $directSales, $precision),
            ])
            ->map(fn ($item) => [
                ...$item,
                'formatted' => (new Money($item['amount'], $currency))->format(),
            ])->all();
    }

    /** {@inheritDoc} */
    public function salesAnalytics(SalesAnalyticsFilter $filter): array
    {
        $user = auth()->user();
        $withRate = ! $user->assignedToBranch();
        $currency = $withRate ? setting('default_currency') : $user->branch->currency;
        $query = Order::query()
            ->withoutCanceledOrders()
            ->orderBy('created_at');

        $scale = Currency::subunit($currency);

        if ($filter === SalesAnalyticsFilter::Weekly) {
            $startOfWeek = startOfWeek();
            $endOfWeek = endOfWeek();
            $query->whereBetween('created_at', [$startOfWeek, $endOfWeek]);

            $rawResults = $query
                ->selectRaw('DATE(created_at) as date')
                ->when(
                    $withRate,
                    fn ($q) => $q->selectRaw('SUM(total * currency_rate) as total_sales'),
                    fn ($q) => $q->selectRaw('SUM(total * currency_rate) as total_sales')
                )
                ->groupByRaw('DATE(created_at)')
                ->get()
                ->mapWithKeys(fn ($row) => [
                    Carbon::parse($row->date)->englishDayOfWeek => round($row->total_sales, $scale),
                ]);

            $days = collect();

            for ($date = $startOfWeek->copy(); $date->lte($endOfWeek); $date->addDay()) {
                $days->push(Day::from(strtolower($date->englishDayOfWeek))->trans());
            }

            $results = $days->map(fn ($day) => [
                'label' => __('support::enums.days.'.strtolower($day)),
                'total_sales' => $rawResults[$day] ?? 0,
            ]);
        } elseif ($filter === SalesAnalyticsFilter::Monthly) {
            $orders = $query
                ->select([
                    'created_at',
                    'total as total_sales',
                    'currency_rate',
                ])
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->get();

            if ($orders->isEmpty()) {
                return ['labels' => [], 'data' => []];
            }

            // Default zeroed week values
            $weekLabels = [
                Monthly::Week1->trans(),
                Monthly::Week2->trans(),
                Monthly::Week3->trans(),
                Monthly::Week4->trans(),
            ];

            $totals = collect(array_fill_keys($weekLabels, 0));

            foreach ($orders as $order) {
                $day = Carbon::parse($order->created_at)->day;
                $week = match (true) {
                    $day <= 7 => Monthly::Week1->trans(),
                    $day <= 14 => Monthly::Week2->trans(),
                    $day <= 21 => Monthly::Week3->trans(),
                    default => Monthly::Week4->trans(),
                };

                $totals[$week] += $order->total_sales * ($withRate ? $order->currency_rate : 1);
            }

            $results = $totals->map(fn ($total, $label) => [
                'label' => $label,
                'total_sales' => round($total, $scale),
            ])->values();
        } else {
            return ['labels' => [], 'data' => []];
        }

        return [
            'labels' => $results
                ->when(RTLDetector::detect(), fn ($results) => $results->reverse())
                ->pluck('label')->all(),
            'data' => $results
                ->when(RTLDetector::detect(), fn ($results) => $results->reverse())
                ->pluck('total_sales')->all(),
            'currency' => $currency,
        ];
    }

    /** {@inheritDoc} */
    public function bestPerformingBranches(AnalyticsPeriod $filter, int $limit = 5): array
    {
        $withRate = ! auth()->user()?->assignedToBranch();

        $query = Order::query()
            ->withoutCanceledOrders()
            ->select([
                'branch_id',
            ])
            ->selectRaw($withRate
                ? 'SUM(total * currency_rate) as total_sales'
                : 'SUM(total) as total_sales')
            ->selectRaw('COUNT(id) as total_orders')
            ->groupBy('branch_id')
            ->orderByDesc('total_sales')
            ->with('branch:id,name,currency');

        // Apply date filters
        match ($filter) {
            AnalyticsPeriod::Today => $query->whereDate('created_at', today()),
            AnalyticsPeriod::ThisWeek => $query->whereBetween('created_at', [startOfWeek(), endOfWeek()]),
            AnalyticsPeriod::ThisMonth => $query->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]),
            AnalyticsPeriod::ThisYear => $query->whereBetween('created_at', [now()->startOfYear(), now()->endOfYear()]),
            AnalyticsPeriod::AllTime => null, // No filter
        };

        $branches = $query->limit($limit)->get();

        return $branches->map(fn ($order) => [
            'branch_id' => $order->branch_id,
            'branch_name' => $order->branch->name,
            'total_orders' => $order->total_orders,
            'total_sales' => $withRate
                ? Money::inDefaultCurrency($order->total_sales)
                : (new Money($order->total_sales, $order->branch->currency)),
        ])->toArray();
    }

    /** {@inheritDoc} */
    public function orderTypeDistribution(AnalyticsPeriod $filter): array
    {
        $query = Order::query()
            ->withoutCanceledOrders()
            ->select('type', DB::raw('COUNT(*) as total_orders'));

        // Filter by date range based on the selected period
        match ($filter) {
            AnalyticsPeriod::Today => $query->whereDate('created_at', now()),
            AnalyticsPeriod::ThisWeek => $query->whereBetween('created_at', [startOfWeek(), endOfWeek()]),
            AnalyticsPeriod::ThisMonth => $query->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]),
            AnalyticsPeriod::ThisYear => $query->whereYear('created_at', now()->year),
            default => null,
        };

        $results = $query
            ->groupBy('type')
            ->orderByDesc('total_orders')
            ->get();

        $types = $results->pluck('type');

        return [
            'labels' => $types->map(fn ($type) => $type->trans())->all(),
            'data' => $results->pluck('total_orders')->all(),
            'colors' => $types->map(fn ($type) => OrderType::getColor($type->value))->all(),
        ];
    }

    /** {@inheritDoc} */
    public function orderTotalByStatus(AnalyticsPeriod $filter): array
    {
        $query = Order::query()
            ->select('status')
            ->selectRaw('COUNT(*) as total_orders');

        // Filter by date range based on the selected period
        match ($filter) {
            AnalyticsPeriod::Today => $query->whereDate('created_at', now()),
            AnalyticsPeriod::ThisWeek => $query->whereBetween('created_at', [startOfWeek(), endOfWeek()]),
            AnalyticsPeriod::ThisMonth => $query->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]),
            AnalyticsPeriod::ThisYear => $query->whereYear('created_at', now()->year),
            default => null,
        };

        $results = $query
            ->groupBy('status')
            ->orderByDesc('total_orders')
            ->get();

        $statuses = $results->pluck('status');

        return [
            'labels' => $statuses->map(fn ($status) => $status->trans())->all(),
            'data' => $results->pluck('total_orders')->all(),
            'colors' => $statuses->map(fn ($status) => $status->color())->all(),
        ];
    }

    /** {@inheritDoc} */
    public function paymentsOverview(AnalyticsPeriod $filter): array
    {
        $user = auth()->user();
        $withRate = ! $user->assignedToBranch();
        $currency = $withRate ? setting('default_currency') : $user->branch->currency;
        $precision = Currency::subunit($currency);
        $query = Payment::query()
            ->whereHas('order', fn ($query) => $query->withoutCanceledOrders())
            ->select([
                'method',
                DB::raw($withRate
                    ? 'SUM(amount * currency_rate) as total_amount'
                    : 'SUM(amount) as total_amount'),
            ])
            ->groupBy('method');

        // Apply period filter
        match ($filter) {
            AnalyticsPeriod::Today => $query->whereDate('created_at', today()),
            AnalyticsPeriod::ThisWeek => $query->whereBetween('created_at', [startOfWeek(), endOfWeek()]),
            AnalyticsPeriod::ThisMonth => $query->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]),
            AnalyticsPeriod::ThisYear => $query->whereBetween('created_at', [now()->startOfYear(), now()->endOfYear()]),
            AnalyticsPeriod::AllTime => null,
        };

        $results = $query->get()->map(fn ($row) => [
            'label' => $row->method->trans(),
            'total_amount' => round($row->total_amount, $precision),
            'color' => PaymentMethod::getColor($row->method->value),
        ]);

        return [
            'labels' => $results->pluck('label')->all(),
            'data' => $results->pluck('total_amount')->all(),
            'colors' => $results->pluck('color')->all(),
            'currency' => $currency,
        ];
    }

    /** {@inheritDoc} */
    public function hourlySalesTrend(): array
    {
        $user = auth()->user();
        $withRate = ! $user->assignedToBranch();
        $currency = $withRate ? setting('default_currency') : $user->branch->currency;
        $precision = Currency::subunit($currency);
        // Base query
        $query = Order::query()
            ->withoutCanceledOrders()
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->orderBy('created_at');

        // Fetch hourly data
        $results = $query
            ->when(
                $withRate,
                fn ($q) => $q->selectRaw('HOUR(created_at) as hour, SUM(total * currency_rate) as total_sales'),
                fn ($q) => $q->selectRaw('HOUR(created_at) as hour, SUM(total) as total_sales'),
            )
            ->groupByRaw('HOUR(created_at)')
            ->pluck('total_sales', 'hour');

        // Initialize 24 hours with 0
        $hours = collect(range(0, 23))->mapWithKeys(function ($hour) use ($results, $precision) {
            return [$hour => round($results[$hour] ?? 0, $precision)];
        });

        return [
            'labels' => $hours
                ->when(RTLDetector::detect(), fn ($results) => $results->reverse())
                ->keys()
                ->map(fn ($h) => Carbon::parse(str_pad($h, 2, '0', STR_PAD_LEFT).':00')
                    ->format(setting('default_time_format')))
                ->toArray(),
            'data' => $hours
                ->when(RTLDetector::detect(), fn ($results) => $results->reverse())
                ->values()
                ->toArray(),
            'currency' => $currency,
        ];
    }

    /** {@inheritDoc} */
    public function branchWiseSalesComparison(AnalyticsPeriod $filter): array
    {
        $query = Order::query()
            ->withoutCanceledOrders()
            ->with('branch:id,name,currency')
            ->select(['branch_id', 'currency'])
            ->selectRaw('SUM(total * currency_rate) as total_sales')
            ->groupBy('branch_id')
            ->orderByDesc('total_sales');

        // Apply period filter
        match ($filter) {
            AnalyticsPeriod::Today => $query->whereDate('created_at', today()),
            AnalyticsPeriod::ThisWeek => $query->whereBetween('created_at', [startOfWeek(), endOfWeek()]),
            AnalyticsPeriod::ThisMonth => $query->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]),
            AnalyticsPeriod::ThisYear => $query->whereBetween('created_at', [now()->startOfYear(), now()->endOfYear()]),
            AnalyticsPeriod::AllTime => null,
        };

        $results = $query->get();

        return [
            'labels' => $results->pluck('branch.name')->toArray(),
            'data' => $results->map(fn ($row) => round($row->total_sales, Currency::subunit($row->currency)))->toArray(),
            'currency' => setting('default_currency'),
        ];
    }

    /** {@inheritDoc} */
    public function cashMovementsOverview(AnalyticsPeriod $filter): array
    {
        $user = auth()->user();
        $withRate = ! $user->assignedToBranch();
        $currency = $withRate ? setting('default_currency') : $user->branch->currency;
        $precision = Currency::subunit($currency);
        $query = PosCashMovement::query()
            ->select([
                'direction',
            ])
            ->selectRaw(
                $withRate
                    ? 'SUM(amount * currency_rate) as total'
                    : 'SUM(amount) as total'
            )
            ->groupBy('direction');

        // Apply date filters
        match ($filter) {
            AnalyticsPeriod::Today => $query->whereDate('created_at', today()),
            AnalyticsPeriod::ThisWeek => $query->whereBetween('created_at', [startOfWeek(), endOfWeek()]),
            AnalyticsPeriod::ThisMonth => $query->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]),
            AnalyticsPeriod::ThisYear => $query->whereBetween('created_at', [now()->startOfYear(), now()->endOfYear()]),
            AnalyticsPeriod::AllTime => null,
        };

        $results = $query->get()->map(fn ($movement) => [
            'label' => $movement->direction->trans(),
            'total' => round($movement->total, $precision),
            'color' => $movement->direction->color(),
        ]);

        return [
            'labels' => $results->pluck('label')->all(),
            'data' => $results->pluck('total')->all(),
            'colors' => $results->pluck('color')->all(),
            'currency' => $currency,
        ];
    }

    /** {@inheritDoc} */
    public function topSellingProducts(AnalyticsPeriod $filter, int $limit = 5): array
    {
        $withRate = ! auth()->user()?->assignedToBranch();

        $query = OrderProduct::query()
            ->with('product', fn ($query) => $query->with('files'))
            ->whereHas('order', fn ($q) => $q->withoutCanceledOrders());

        // Apply period filter
        match ($filter) {
            AnalyticsPeriod::Today => $query->whereDate('created_at', today()),
            AnalyticsPeriod::ThisWeek => $query->whereBetween('created_at', [startOfWeek(), endOfWeek()]),
            AnalyticsPeriod::ThisMonth => $query->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]),
            AnalyticsPeriod::ThisYear => $query->whereBetween('created_at', [now()->startOfYear(), now()->endOfYear()]),
            AnalyticsPeriod::AllTime => null,
        };

        $results = $query->get()
            ->groupBy('product_id')
            ->map(function ($items) use ($withRate) {
                $firstItem = $items->first();
                $product = $firstItem->product;
                $totalSales = $items->sum(fn ($item) => $item->total->amount() * ($withRate ? $item->currency_rate : 1));

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'thumbnail' => $product->thumbnail != null ? $product->thumbnail->preview_image_url : null,
                    'total_quantity' => $items->sum('quantity'),
                    'total_sales' => $withRate
                        ? Money::inDefaultCurrency($totalSales)
                        : new Money($totalSales, $firstItem->currency),
                ];
            })
            ->sortByDesc('total_quantity')
            ->take($limit)
            ->values();

        return $results->toArray();
    }

    /**
     * Get low stock ingredients
     */
    public function getLowStockAlerts(): array
    {
        return Ingredient::query()
            ->whereColumn('current_stock', '<', 'alert_quantity')
            ->orderBy('current_stock')
            ->get()
            ->map(fn (Ingredient $ingredient) => [
                'id' => $ingredient->id,
                'name' => $ingredient->name,
                'current_stock' => $ingredient->current_stock,
                'alert_quantity' => $ingredient->alert_quantity,
                'symbol' => strtoupper($ingredient->unit->symbol),
            ])
            ->toArray();
    }

    /** {@inheritDoc} */
    public function systemHealth(): array
    {
        $start = microtime(true);

        try {
            DB::select('SELECT 1');
            $dbHealthy = true;
            $dbLatency = round((microtime(true) - $start) * 1000, 2);
        } catch (\Exception) {
            $dbHealthy = false;
            $dbLatency = null;
        }

        $diskFree = function_exists('disk_free_space') ? disk_free_space(base_path()) : false;
        $diskTotal = function_exists('disk_total_space') ? disk_total_space(base_path()) : false;
        $diskUsage = $diskFree !== false && $diskTotal !== false && $diskTotal > 0
            ? round((1 - ($diskFree / $diskTotal)) * 100, 2)
            : null;

        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);

        $formatBytes = function (int $bytes): string {
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $unitIndex = 0;
            while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
                $bytes /= 1024;
                $unitIndex++;
            }

            return round($bytes, 2).' '.$units[$unitIndex];
        };

        return [
            'database' => [
                'healthy' => $dbHealthy,
                'latency_ms' => $dbLatency,
                'connection' => config('database.default'),
            ],
            'cache' => [
                'driver' => config('cache.default'),
                'healthy' => $this->cacheIsHealthy(),
            ],
            'queue' => [
                'driver' => config('queue.default'),
                'pending_jobs' => $this->queueBacklog(['default', 'notifications', 'emails', 'aggregator-sync', 'aggregator-webhooks']),
                'failed_jobs' => $this->failedJobsCount(),
            ],
            'disk' => [
                'usage_percentage' => $diskUsage,
                'free_human' => $diskUsage !== null ? $formatBytes($diskFree) : null,
            ],
            'memory' => [
                'current_bytes' => $memoryUsage,
                'current_human' => $formatBytes($memoryUsage),
                'peak_bytes' => $memoryPeak,
                'peak_human' => $formatBytes($memoryPeak),
            ],
            'app' => [
                'environment' => app()->environment(),
                'debug' => config('app.debug'),
                'version' => config('app.version', '1.0.0'),
                'php_version' => PHP_VERSION,
                'laravel_version' => \Illuminate\Foundation\Application::VERSION,
                'timezone' => config('app.timezone'),
            ],
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /** {@inheritDoc} */
    public function globalSearch(string $query, int $limit = 10): array
    {
        $products = Product::query()
            // menu_id is required: Product eager-loads a belongs-to-through
            // relation keyed on it, and omitting the column makes the whole
            // search throw MissingAttributeException under strict models.
            ->select(['id', 'menu_id', 'name', 'sku', 'price', 'is_active'])
            ->where(fn ($builder) => $builder
                ->whereLikeTranslation('name', $query)
                ->orWhere('sku', 'like', "%{$query}%"))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'subtitle' => $product->sku,
                'price' => $product->price,
                'status' => $product->is_active ? 'active' : 'inactive',
                'type' => 'product',
                'url' => "/admin/products/{$product->id}/edit",
            ])
            ->toArray();

        $customers = User::query()
            ->select(['id', 'name', 'email', 'phone'])
            ->role(DefaultRole::Customer)
            ->where(fn ($builder) => $builder
                ->where('name', 'like', "%{$query}%")
                ->orWhere('email', 'like', "%{$query}%")
                ->orWhere('phone', 'like', "%{$query}%"))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'subtitle' => $user->email ?? $user->phone,
                'price' => null,
                'status' => null,
                'type' => 'customer',
                'url' => "/admin/customers/{$user->id}/edit",
            ])
            ->toArray();

        $orders = Order::query()
            ->select(['id', 'reference_no', 'order_number', 'total', 'status'])
            ->where(fn ($builder) => $builder
                ->where('reference_no', 'like', "%{$query}%")
                ->orWhere('order_number', 'like', "%{$query}%")
                ->when(is_numeric($query), fn ($numericQuery) => $numericQuery->orWhere('id', (int) $query)))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'name' => $order->reference_no,
                'subtitle' => $order->status?->value,
                'price' => $order->total,
                'status' => $order->status?->value,
                'type' => 'order',
                'url' => "/admin/orders/{$order->id}/show",
            ])
            ->toArray();

        return [
            'query' => $query,
            'results' => array_merge($products, $customers, $orders),
            'counts' => [
                'products' => count($products),
                'customers' => count($customers),
                'orders' => count($orders),
                'total' => count($products) + count($customers) + count($orders),
            ],
        ];
    }

    /** {@inheritDoc} */
    public function commandPalette(): array
    {
        $user = Auth::user();

        $actions = [
            ['id' => 'orders', 'label' => __('dashboard::dashboards.command_palette.orders'), 'icon' => 'tabler-receipt', 'route' => '/admin/orders', 'permission' => 'admin.orders.index'],
            ['id' => 'products', 'label' => __('dashboard::dashboards.command_palette.products'), 'icon' => 'tabler-box', 'route' => '/admin/products', 'permission' => 'admin.products.index'],
            ['id' => 'customers', 'label' => __('dashboard::dashboards.command_palette.customers'), 'icon' => 'tabler-users', 'route' => '/admin/customers', 'permission' => 'admin.customers.index'],
            ['id' => 'reports', 'label' => __('dashboard::dashboards.command_palette.reports'), 'icon' => 'tabler-chart-bar', 'route' => '/admin/reports', 'permission' => 'admin.reports.index', 'feature' => 'reports'],
            ['id' => 'dashboard', 'label' => __('dashboard::dashboards.command_palette.dashboard'), 'icon' => 'tabler-layout-dashboard', 'route' => '/admin', 'permission' => [
                'admin.dashboards.total_sales', 'admin.dashboards.total_orders',
                'admin.dashboards.total_active_orders', 'admin.dashboards.average_order_value',
                'admin.dashboards.total_users', 'admin.dashboards.total_menus',
                'admin.dashboards.total_products', 'admin.dashboards.total_categories',
            ]],
            ['id' => 'settings', 'label' => __('dashboard::dashboards.command_palette.settings'), 'icon' => 'tabler-settings', 'route' => '/admin/settings/general', 'permission' => 'admin.settings.edit'],
            ['id' => 'branches', 'label' => __('dashboard::dashboards.command_palette.branches'), 'icon' => 'tabler-building', 'route' => '/admin/branches', 'permission' => 'admin.branches.index'],
            ['id' => 'users', 'label' => __('dashboard::dashboards.command_palette.users'), 'icon' => 'tabler-user-shield', 'route' => '/admin/users', 'permission' => 'admin.users.index'],
            ['id' => 'roles', 'label' => __('dashboard::dashboards.command_palette.roles'), 'icon' => 'tabler-key', 'route' => '/admin/roles', 'permission' => 'admin.roles.index'],
            ['id' => 'tables', 'label' => __('dashboard::dashboards.command_palette.tables'), 'icon' => 'tabler-table', 'route' => '/admin/tables', 'permission' => 'admin.tables.index'],
            ['id' => 'reservations', 'label' => __('dashboard::dashboards.command_palette.reservations'), 'icon' => 'tabler-calendar-event', 'route' => '/admin/reservations', 'permission' => 'admin.reservations.index'],
            ['id' => 'vouchers', 'label' => __('dashboard::dashboards.command_palette.vouchers'), 'icon' => 'tabler-ticket', 'route' => '/admin/vouchers', 'permission' => 'admin.vouchers.index'],
            ['id' => 'printers', 'label' => __('dashboard::dashboards.command_palette.printers'), 'icon' => 'tabler-printer', 'route' => '/admin/printers', 'permission' => 'admin.printers.index'],
        ];

        // An action may declare several acceptable permissions; holding any one
        // is enough. The Dashboard entry needs this because there is no single
        // "overview" permission — the dashboard is guarded by a set.
        $filtered = array_values(array_filter($actions, function (array $action) use ($user): bool {
            if (! $user) {
                return false;
            }

            return collect((array) $action['permission'])
                ->contains(fn (string $permission): bool => $user->can($permission));
        }));

        return [
            'actions' => $filtered,
            'recent' => [],
            'favorites' => [],
        ];
    }

    private function cacheIsHealthy(): bool
    {
        try {
            Cache::put('system_health_check', true, now()->addMinutes(1));

            return Cache::get('system_health_check') === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
