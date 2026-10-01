<?php

namespace Modules\Loyalty\Services\Loyalty;


use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Currency\Models\CurrencyRate;
use Modules\Loyalty\Enums\LoyaltyPromotionType;
use Modules\Loyalty\Enums\LoyaltyTransactionType;
use Modules\Loyalty\Models\LoyaltyCustomer;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Loyalty\Models\LoyaltyPromotion;
use Modules\Loyalty\Models\LoyaltyTier;
use Modules\Loyalty\Models\LoyaltyTransaction;
use Modules\Order\Models\Order;


class LoyaltyService implements LoyaltyServiceInterface
{
    /** @inheritDoc */
    public function adjustPoints(LoyaltyCustomer $customer, int $points, ?string $reason = null, ?int $userId = null): LoyaltyTransaction
    {
        if ($points === 0) {
            throw ValidationException::withMessages([
                'points' => __('loyalty::messages.adjust_points_non_zero'),
            ]);
        }

        return DB::transaction(function () use ($customer, $points, $reason, $userId) {
            $lc = LoyaltyCustomer::query()->lockForUpdate()->findOrFail($customer->id);

            // A debit cannot push the balance below zero.
            if ($points < 0 && ($lc->points_balance + $points) < 0) {
                throw ValidationException::withMessages([
                    'points' => __('loyalty::messages.adjust_points_insufficient'),
                ]);
            }

            $transaction = LoyaltyTransaction::query()->create([
                'loyalty_customer_id' => $lc->id,
                'order_id' => null,
                'type' => LoyaltyTransactionType::Adjust,
                'points' => $points,
                'amount' => 0,
                'meta' => [
                    'manual' => true,
                    'reason' => $reason,
                    'adjusted_by' => $userId,
                ],
            ]);

            $lc->points_balance += $points;
            // Only credits raise lifetime points; a debit never reduces lifetime totals.
            if ($points > 0) {
                $lc->lifetime_points += $points;
            }
            $lc->save();

            return $transaction;
        });
    }

    /** @inheritDoc */
    public function earnForOrder(Order $order, array $context = []): ?array
    {
        return DB::transaction(function () use ($order, $context) {
            // Serialize repeated completion events for one order before the
            // duplicate check. The member row may not exist on first earn.
            DB::table('orders')->where('id', $order->id)->lockForUpdate()->first();
            $order->loadMissing('branch:id,tenant_id');
            $tenantId = $order->branch?->tenant_id;
            if (! $tenantId) return null;
            $program = $this->resolveProgram($context['program_id'] ?? null, $tenantId);
            if (is_null($order->customer_id) || is_null($program)) {
                return null;
            }

            $order->loadMissing('customer');
            if (! $order->customer || (int) $order->customer->tenant_id !== (int) $tenantId) return null;

            $exists = LoyaltyTransaction::query()
                ->where('order_id', $order->id)
                ->whereIn('type', [LoyaltyTransactionType::Earn->value, LoyaltyTransactionType::Bonus->value])
                ->exists();
            if ($exists) {
                $summary = $this->summaryForOrder($order->id);
                return $summary + ['customer_id' => $summary['customer_id'], 'tier_id' => $summary['tier_id']];
            }

            $lc = LoyaltyCustomer::query()
                ->where('customer_id', $order->customer_id)
                ->where('loyalty_program_id', $program->id)
                ->lockForUpdate()
                ->first();

            if (!$lc) {
                $lc = new LoyaltyCustomer();
                $lc->customer_id = $order->customer_id;
                $lc->loyalty_program_id = $program->id;
                $lc->loyalty_tier_id = null;
                $lc->points_balance = 0;
                $lc->lifetime_points = 0;
                $lc->save();
            }

            $orderAmount = $order->total->amount();
            $currency = $order->currency;
            $rate = $this->resolveCurrencyRate($currency, $order->currency_rate);
            $baseAmount = $this->toBaseCurrency($orderAmount, $rate);

            if ($baseAmount <= 0) {
                return [
                    'points' => 0,
                    'bonus' => 0,
                    'total' => 0,
                    'customer_id' => $lc->id,
                    'tier_id' => $lc->loyalty_tier_id,
                ];
            }

            $tier = $lc->loyalty_tier_id ? LoyaltyTier::query()->find($lc->loyalty_tier_id) : null;
            $tierMultiplier = ($tier && $tier->is_active) ? (float)$tier->multiplier : 1.0;

            $basePoints = (int)floor($baseAmount * $program->earning_rate->amount() * $tierMultiplier);

            $promotionIds = $context['promotions'] ?? null;
            $promos = $this->resolveApplicablePromotions($program, $order, $baseAmount, $promotionIds);

            [$multiplierBonusPoints, $flatBonusPoints, $promoMeta, $promoCounters] = $this->applyPromotions(
                program: $program,
                order: $order,
                basePoints: $basePoints,
                tierMultiplier: $tierMultiplier,
                promotions: $promos
            );

            $totalPoints = max(0, $basePoints + $multiplierBonusPoints + $flatBonusPoints);

            $now = Carbon::now();
            $validUntil = $program->points_expire_after ? $now->copy()->addDays((int)$program->points_expire_after) : null;

            if ($basePoints > 0) {
                LoyaltyTransaction::query()->create([
                    'loyalty_customer_id' => $lc->id,
                    'order_id' => $order->id,
                    'type' => LoyaltyTransactionType::Earn,
                    'points' => $basePoints,
                    'amount' => $baseAmount,
                    'meta' => [
                        'description' => [
                            'text' => 'loyalty::messages.order_completed',
                            'replace' => ['id' => $order->reference_no],
                        ],
                        'program_id' => $program->id,
                        'currency' => $currency,
                        'currency_rate' => $rate,
                        'valid_until' => $validUntil?->toDateString(),
                        'tier_multiplier' => $tierMultiplier,
                    ],
                ]);
            }

            $bonusTotal = 0;
            if (!empty($promoMeta['promotions'])) {
                foreach ($promoMeta['promotions'] as $promoEntry) {
                    $points = (int)$promoEntry['applied_points'];
                    if ($points <= 0) {
                        continue;
                    }

                    $bonusTotal += $points;

                    LoyaltyTransaction::query()->create([
                        'loyalty_customer_id' => $lc->id,
                        'order_id' => $order->id,
                        'type' => LoyaltyTransactionType::Bonus,
                        'points' => $points,
                        'amount' => 0,
                        'meta' => [
                            'description' => [
                                'text' => 'loyalty::messages.promotion_applied',
                                'replace' => ['id' => $promoEntry['promotion_id']],
                            ],
                            'program_id' => $program->id,
                            'promotion_id' => $promoEntry['promotion_id'],
                            'promotion_type' => $promoEntry['type'],
                            'currency' => $currency,
                            'currency_rate' => $rate,
                            'valid_until' => $validUntil?->toDateString(),
                        ],
                    ]);
                }
            }

            foreach ($promoCounters as $promoId => $counts) {
                /** @var LoyaltyPromotion $p */
                $p = LoyaltyPromotion::query()->lockForUpdate()->find($promoId);
                if ($p) {
                    if (isset($counts['total_used'])) {
                        $p->total_used += (int)$counts['total_used'];
                    }
                    if (isset($counts['total_customers'])) {
                        $p->total_customers += (int)$counts['total_customers'];
                    }
                    $p->save();
                }
            }

            $lc->points_balance += $totalPoints;
            $lc->lifetime_points += $totalPoints;
            $lc->last_earned_at = $now;
            $lc->save();

            $newTierId = $this->recomputeTier($lc, $program);

            return [
                'points' => $basePoints,
                'bonus' => $bonusTotal,
                'total' => $totalPoints,
                'customer_id' => $lc->id,
                'tier_id' => $newTierId,
            ];
        });
    }

    /**
     * Resolve program
     *
     * @param int|null $programId
     * @return LoyaltyProgram|null
     */
    protected function resolveProgram(?int $programId, ?int $tenantId = null): ?LoyaltyProgram
    {
        $query = LoyaltyProgram::query()->when($tenantId, fn ($query) => $query->forTenant($tenantId));
        if ($programId) return $query->findOrFail($programId);

        return LoyaltyProgram::currentForTenant($tenantId);
    }

    /**
     * Return an order summary of loyalty points already recorded.
     *
     * @param int $orderId
     * @return array
     */
    protected function summaryForOrder(int $orderId): array
    {
        $first = LoyaltyTransaction::query()
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->first();

        if (!$first) {
            return ['points' => 0, 'bonus' => 0, 'total' => 0, 'customer_id' => 0, 'tier_id' => null];
        }

        $points = (int)LoyaltyTransaction::query()
            ->where('order_id', $orderId)
            ->where('type', LoyaltyTransactionType::Earn)
            ->sum('points');
        $bonus = (int)LoyaltyTransaction::query()
            ->where('order_id', $orderId)
            ->where('type', LoyaltyTransactionType::Bonus)
            ->sum('points');

        $lc = LoyaltyCustomer::query()->find($first->loyalty_customer_id);

        return [
            'points' => $points,
            'bonus' => $bonus,
            'total' => $points + $bonus,
            'customer_id' => $lc?->id ?? 0,
            'tier_id' => $lc?->loyalty_tier_id,
        ];
    }

    /**
     * Resolve conversion rate to base currency.
     *
     * @param string $currency
     * @param float|null $incomingRate
     * @return float|int
     */
    protected function resolveCurrencyRate(string $currency, ?float $incomingRate): float|int
    {
        if (!is_null($incomingRate)) {
            return $incomingRate;
        }

        return CurrencyRate::for($currency);
    }

    /**
     * Convert to base currency
     *
     * @param float $amount
     * @param float $rate
     * @return float
     */
    protected function toBaseCurrency(float $amount, float $rate): float
    {
        return round($amount / $rate, 3);
    }

    /**
     * Fetch promotions to consider, filtered by active window and coarse conditions.
     * If $promotionIds provided, restrict to those; otherwise auto-discover active promos.
     *
     * @param LoyaltyProgram $program
     * @param Order $order
     * @param float $baseAmount
     * @param array|null $promotionIds
     * @return Collection
     */
    protected function resolveApplicablePromotions(
        LoyaltyProgram $program,
        Order          $order,
        float          $baseAmount,
        ?array         $promotionIds = null
    ): Collection
    {
        return LoyaltyPromotion::query()
            ->where('loyalty_program_id', $program->id)
            ->where(function ($q) {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', Carbon::today()->toDateString());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', Carbon::today()->toDateString());
            })
            ->when(!is_null($promotionIds), fn($q) => $q->whereIn('id', $promotionIds))
            ->get()
            ->filter(function (LoyaltyPromotion $p) use ($order, $baseAmount) {
                $cond = ($p->conditions ?? []);

                $minSpend = isset($cond['min_spend']) ? (float)$cond['min_spend'] : null;
                if ($minSpend !== null && $baseAmount < $minSpend) return false;

                if (!empty($cond['available_days']) && is_array($cond['available_days'])) {
                    $dow = strtolower(today()->englishDayOfWeek);
                    if (!in_array($dow, $cond['available_days'], true)) return false;
                }

                if (!empty($cond['branch_ids']) && is_array($cond['branch_ids'])) {
                    if (!in_array((int)$order->branch_id, array_map('intval', $cond['branch_ids']), true)) return false;
                }

                if ($p->type === LoyaltyPromotionType::NewMember) {
                    $validDays = Arr::get($cond, 'valid_days');
                    if (!$validDays) return false;

                    $customer = $order->customer;
                    if (!$customer) return false;

                    $daysSinceRegister = $customer->created_at->diffInDays(now());
                    if ($daysSinceRegister > (int)$validDays) {
                        return false;
                    }

                    $hasEarned = LoyaltyTransaction::query()
                        ->whereHas('customer', function ($q) use ($order) {
                            $q->where('customer_id', $order->customer_id);
                        })
                        ->where('type', 'earn')
                        ->exists();

                    if ($hasEarned) return false;
                }

                return true;
            })->values();
    }

    /**
     * Compute promotion-derived points and produce meta & counter increments.
     * Returns [multiplierBonusPoints, flatBonusPoints, promoMeta, promoCounters]
     *
     * @param LoyaltyProgram $program
     * @param Order $order
     * @param int $basePoints
     * @param float $tierMultiplier
     * @param Collection<LoyaltyPromotion> $promotions
     * @return array
     */
    protected function applyPromotions(
        LoyaltyProgram $program,
        Order          $order,
        int            $basePoints,
        float          $tierMultiplier,
        Collection     $promotions
    ): array
    {
        $multiplierBonus = 0;
        $flatBonus = 0;
        $meta = ['promotions' => []];
        $counters = [];

        /** @var LoyaltyPromotion $promotion */
        foreach ($promotions as $promotion) {
            if ($promotion->usage_limit !== null && (int)$promotion->total_used >= (int)$promotion->usage_limit) continue;

            $customerCount = LoyaltyTransaction::query()
                ->whereHas('customer', function ($q) use ($order, $promotion) {
                    $q->where('customer_id', $order->customer_id)
                        ->where('loyalty_program_id', $promotion->loyalty_program_id);
                })
                ->whereIn('type', [LoyaltyTransactionType::Earn, LoyaltyTransactionType::Bonus])
                ->where('meta->promotion_id', $promotion->id)
                ->count();

            if ($promotion->per_customer_limit !== null && $customerCount >= (int)$promotion->per_customer_limit) continue;

            $extra = 0;

            switch ($promotion->type) {
                case LoyaltyPromotionType::Multiplier:
                    $m = max(1.0, (float)$promotion->multiplier);
                    $extra = (int)floor($basePoints * ($m - 1.0));
                    $multiplierBonus += max(0, $extra);
                    break;

                case LoyaltyPromotionType::CategoryBoost:
                    $boostCats = (array)(($promotion->conditions['categories'] ?? []) ?: []);
                    if ($boostCats) {
                        $order->load(['products' => fn($q) => $q->with('product.categories')]);

                        $orderCurrencySpendInCats = 0.0;
                        foreach ($order->products as $product) {
                            $productCats = $product->product->categories->pluck('slug')->toArray();
                            if (count(array_intersect($boostCats, $productCats)) > 0) {
                                $orderCurrencySpendInCats += (float)$product->total->amount();
                            }
                        }

                        $rate = $this->resolveCurrencyRate($order->currency, $order->currency_rate);
                        $baseSpendInCats = $this->toBaseCurrency($orderCurrencySpendInCats, $rate);

                        $m = max(1.0, (float)$promotion->multiplier);
                        $pointsInCats = (int)floor($baseSpendInCats * $program->earning_rate->amount() * $tierMultiplier);
                        $extra = (int)floor($pointsInCats * ($m - 1.0));
                        $multiplierBonus += max(0, $extra);
                    }
                    break;

                case LoyaltyPromotionType::BonusPoints:
                case LoyaltyPromotionType::NewMember:
                    $bonus = max(0, (int)$promotion->bonus_points);
                    $flatBonus += $bonus;
                    $extra = $bonus;
                    break;

                default:
                    break;
            }

            if ($extra > 0) {
                $meta['promotions'][] = [
                    'promotion_id' => $promotion->id,
                    'type' => $promotion->type,
                    'applied_points' => $extra,
                ];

                $counters[$promotion->id]['total_used'] = ($counters[$promotion->id]['total_used'] ?? 0) + 1;
                $counters[$promotion->id]['total_customers'] = ($counters[$promotion->id]['total_customers'] ?? 0);
                if ($customerCount === 0) {
                    $counters[$promotion->id]['total_customers'] += 1;
                }
            }
        }

        return [$multiplierBonus, $flatBonus, $meta, $counters];
    }

    /** @inheritDoc */
    public function recomputeTier(LoyaltyCustomer $lc, LoyaltyProgram $program): ?int
    {
        $earningRate = max(0.000001, $program->earning_rate->amount());
        $estimatedSpend = $lc->lifetime_points / $earningRate;
        $tiers = LoyaltyTier::query()
            ->where('loyalty_program_id', $program->id)
            ->orderBy('min_spend')
            ->get();


        $newTier = null;

        /** @var LoyaltyTier $tier */
        foreach ($tiers as $tier) {
            if ($estimatedSpend >= $tier->min_spend->amount()) {
                $newTier = $tier;
            } else {
                break;
            }
        }

        $newTierId = $newTier?->id;
        $allowUpdate = $lc->loyalty_tier_id !== $newTierId;

        if (!is_null($lc->loyaltyTier) && $allowUpdate && !is_null($newTier)) {
            $isDowngrade = $newTier->min_spend->amount() < $lc->loyaltyTier->min_spend->amount();

            if ($isDowngrade && $lc->force) {
                $allowUpdate = false;
            }
        }

        if ($allowUpdate) {
            $lc->loyalty_tier_id = $newTierId;
            $lc->force = false;
            $lc->save();
        }

        return $newTierId;
    }

    /** @inheritDoc */
    public function cancelForOrder(Order $order, array $context = []): array
    {
        return DB::transaction(function () use ($order, $context) {
            /** @var LoyaltyTransaction|null $anyTx */
            $anyTx = LoyaltyTransaction::query()
                ->where('order_id', $order->id)
                ->whereIn('type', [LoyaltyTransactionType::Earn, LoyaltyTransactionType::Bonus])
                ->latest()
                ->lockForUpdate()
                ->first();

            if (!$anyTx) {
                return ['reversed' => 0, 'remaining_balance' => 0];
            }

            $lc = LoyaltyCustomer::query()
                ->with("loyaltyTier")
                ->lockForUpdate()
                ->findOrFail($anyTx->loyalty_customer_id);

            $program = LoyaltyProgram::query()
                ->findOrFail($lc->loyalty_program_id);

            $currency = $order->currency;
            $rate = $this->resolveCurrencyRate($currency, $order->currency_rate);

            $partialAmount = Arr::get($context, 'partial_amount');
            $partialBase = $partialAmount === null ? null : $this->toBaseCurrency((float)$partialAmount, $rate);

            $orderTotalBase = $this->toBaseCurrency((float)$order->total->amount(), $rate);
            $proportion = 1.0;
            if ($partialBase !== null && $orderTotalBase > 0) {
                $proportion = max(0.0, min(1.0, $partialBase / $orderTotalBase));
            }

            $earnedPoints = (int)LoyaltyTransaction::query()
                ->where('order_id', $order->id)
                ->where('type', LoyaltyTransactionType::Earn)
                ->sum('points');

            $bonusPoints = (int)LoyaltyTransaction::query()
                ->where('order_id', $order->id)
                ->where('type', LoyaltyTransactionType::Bonus)
                ->sum('points');

            $totalOrderPoints = $earnedPoints + $bonusPoints;
            $toReverse = (int)floor($totalOrderPoints * $proportion);
            if ($toReverse <= 0) {
                return ['reversed' => 0, 'remaining_balance' => (int)$lc->points_balance];
            }

            $reason = $context['reason'] ?? 'order_cancelled_or_refunded';
            $remainingToReverse = $toReverse;

            // Fetch source Earn/Bonus transactions FIFO for this order
            $sourceTxs = LoyaltyTransaction::query()
                ->where('order_id', $order->id)
                ->whereIn('type', [LoyaltyTransactionType::Earn, LoyaltyTransactionType::Bonus])
                ->where('points', '>', 0)
                ->orderBy('id')
                ->get(['id', 'points', 'order_id', 'meta']);

            $reversedTotal = 0;

            /** @var LoyaltyTransaction $src */
            foreach ($sourceTxs as $src) {
                if ($remainingToReverse <= 0) {
                    break;
                }

                // Determine remaining available points for this source
                $usedOnSource = (int)LoyaltyTransaction::query()
                    ->where('loyalty_customer_id', $lc->id)
                    ->whereIn('type', [LoyaltyTransactionType::Adjust, LoyaltyTransactionType::Expire])
                    ->where('meta->source_tx', $src->id)
                    ->sum('points'); // negative or zero
                $availableFromSource = max(0, (int)$src->points + $usedOnSource);

                if ($availableFromSource <= 0) {
                    continue;
                }

                $chunk = min($availableFromSource, $remainingToReverse);
                LoyaltyTransaction::query()->create([
                    'loyalty_customer_id' => $lc->id,
                    'order_id' => $order->id,
                    'type' => LoyaltyTransactionType::Adjust,
                    'points' => -$chunk,
                    'amount' => 0,
                    'meta' => [
                        'description' => [
                            'text' => 'loyalty::messages.order_cancelled',
                            'replace' => ['id' => $order->id],
                        ],
                        'program_id' => $program->id,
                        'reason' => $reason,
                        'source_tx' => $src->id,
                    ],
                ]);

                $remainingToReverse -= $chunk;
                $reversedTotal += $chunk;
            }


            if ($reversedTotal > 0) {
                $lc->points_balance = max(0, (int)$lc->points_balance - $reversedTotal);
                $lc->lifetime_points = max(0, (int)$lc->lifetime_points - $reversedTotal);
                $lc->save();

                $this->recomputeTier($lc, $program);
            }

            return [
                'reversed' => $reversedTotal,
                'remaining_balance' => (int)$lc->points_balance,
            ];
        });
    }

    /** @inheritDoc */
    public function expirePoints(int $batch = 100): int
    {
        $today = Carbon::today()->toDateString();
        $expiredTotal = 0;

        $customers = LoyaltyCustomer::query()
            ->where('points_balance', '>', 0)
            ->whereNull('deleted_at')
            ->whereExists(function ($q) use ($today) {
                $q->selectRaw('1')
                    ->from('loyalty_transactions')
                    ->whereColumn('loyalty_transactions.loyalty_customer_id', 'loyalty_customers.id')
                    ->whereNull('loyalty_transactions.deleted_at')
                    ->whereIn('loyalty_transactions.type', [
                        LoyaltyTransactionType::Earn->value,
                        LoyaltyTransactionType::Bonus->value,
                    ])
                    ->where('loyalty_transactions.points', '>', 0)
                    ->where('loyalty_transactions.meta->valid_until', '<', $today);
            })
            ->orderBy('id')
            ->limit($batch)
            ->get(['id', 'points_balance']);

        if ($customers->isEmpty()) {
            return 0;
        }

        $customerIds = $customers->pluck('id')->map(fn($v) => (int)$v)->all();

        DB::transaction(function () use (&$expiredTotal, $customerIds, $today) {

            DB::table('loyalty_customers')
                ->whereIn('id', $customerIds)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->get(['id']);


            DB::table('loyalty_transactions')
                ->whereIn('loyalty_customer_id', $customerIds)
                ->whereNull('deleted_at')
                ->whereIn('type', [
                    LoyaltyTransactionType::Earn->value,
                    LoyaltyTransactionType::Bonus->value,
                    LoyaltyTransactionType::Redeem->value,
                    LoyaltyTransactionType::Adjust->value,
                    LoyaltyTransactionType::Expire->value,
                ])
                ->lockForUpdate()
                ->get(['id']);


            $expiredTxs = DB::table('loyalty_transactions')
                ->select([
                    'id',
                    'loyalty_customer_id',
                    'order_id',
                    'points',
                    DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.valid_until')) as valid_until"),
                ])
                ->whereNull('deleted_at')
                ->whereIn('loyalty_customer_id', $customerIds)
                ->whereIn('type', [
                    LoyaltyTransactionType::Earn->value,
                    LoyaltyTransactionType::Bonus->value,
                ])
                ->where('points', '>', 0)
                ->whereRaw(
                    "CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.valid_until')) AS DATE) < ?",
                    [$today]
                )
                ->orderBy('loyalty_customer_id')
                ->orderByRaw("COALESCE(CAST(meta->>'valid_until' AS DATE), '9999-12-31')")
                ->orderBy('id')
                ->get();

            if ($expiredTxs->isEmpty()) {
                $expiredTotal = 0;
                return;
            }


            $alreadyExpired = DB::table('loyalty_transactions')
                ->select('meta->source_tx as source_tx')
                ->whereIn('loyalty_customer_id', $customerIds)
                ->where('type', LoyaltyTransactionType::Expire->value)
                ->whereNull('deleted_at')
                ->pluck('source_tx')
                ->filter()
                ->map(fn($v) => (int)$v)
                ->all();

            $usedPointsBySource = DB::table('loyalty_transactions')
                ->select(
                    'meta->source_tx as source_tx'
                )
                ->selectRaw("SUM(points) as used_points")
                ->whereIn('loyalty_customer_id', $customerIds)
                ->whereNull('deleted_at')
                ->whereIn('type', [
                    LoyaltyTransactionType::Adjust->value,
                    LoyaltyTransactionType::Expire->value,
                    LoyaltyTransactionType::Redeem->value,
                ])
                ->whereNotNull('meta->source_tx')
                ->groupBy('source_tx')
                ->pluck('used_points', 'source_tx');

            $expireRows = [];
            $expiredByCustomer = [];

            foreach ($expiredTxs as $tx) {
                $txId = (int)$tx->id;
                if (in_array($txId, $alreadyExpired, true)) {
                    continue;
                }

                $custId = (int)$tx->loyalty_customer_id;
                $earned = (int)$tx->points;

                $used = (int)($usedPointsBySource[$txId] ?? 0);

                $remaining = max(0, $earned + $used);
                
                if ($remaining <= 0) {
                    continue;
                }

                $expireRows[] = [
                    'loyalty_customer_id' => $custId,
                    'order_id' => $tx->order_id,
                    'type' => LoyaltyTransactionType::Expire->value,
                    'points' => -$remaining,
                    'amount' => 0,
                    'meta' => json_encode([
                        'description' => [
                            'text' => 'loyalty::messages.points_expired',
                            'replace' => ['id' => $txId],
                        ],
                        'source_tx' => $txId,
                        'valid_until' => $tx->valid_until,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $expiredByCustomer[$custId] = ($expiredByCustomer[$custId] ?? 0) + $remaining;
            }

            if (empty($expireRows)) {
                $expiredTotal = 0;
                return;
            }

            foreach (array_chunk($expireRows, 1000) as $chunk) {
                DB::table('loyalty_transactions')->insert($chunk);
            }

            $ids = array_keys($expiredByCustomer);
            $casesSql = [];
            $bindings = [];

            foreach ($expiredByCustomer as $custId => $points) {
                $custId = (int)$custId;
                $points = (int)$points;
                $casesSql[] = "WHEN ? THEN GREATEST(points_balance - ?, 0)";
                $bindings[] = $custId;
                $bindings[] = $points;
            }

            if (!empty($ids)) {
                $inPlaceholders = implode(',', array_fill(0, count($ids), '?'));
                $bindings = array_merge(
                    $bindings,
                    array_map('intval', $ids)
                );

                $sql = "UPDATE loyalty_customers
                    SET points_balance = CASE id " . implode(' ', $casesSql) . " END
                    WHERE id IN ($inPlaceholders) AND deleted_at IS NULL";

                DB::update($sql, $bindings);
            }

            $expiredTotal = array_sum($expiredByCustomer);
        });

        return (int)$expiredTotal;
    }

}
