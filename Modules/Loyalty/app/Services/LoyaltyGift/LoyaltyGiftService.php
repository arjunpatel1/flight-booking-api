<?php

namespace Modules\Loyalty\Services\LoyaltyGift;

use App\NexDine;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Discount\Models\Discount;
use Modules\Loyalty\Enums\LoyaltyGiftStatus;
use Modules\Loyalty\Enums\LoyaltyRewardType;
use Modules\Loyalty\Enums\LoyaltyTransactionType;
use Modules\Loyalty\Models\{LoyaltyCustomer, LoyaltyGift, LoyaltyProgram, LoyaltyReward, LoyaltyTransaction};
use Modules\Media\Transformers\Api\V1\MediaSimpleResource;
use Modules\Order\Models\Order;
use Modules\Support\GlobalStructureFilters;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;
use Modules\Voucher\Models\Voucher;
use Random\RandomException;

class LoyaltyGiftService implements LoyaltyGiftServiceInterface
{
    /** @inheritDoc */
    public function getStructureFilters(?int $programId = null): array
    {
        return [
            [
                "key" => 'status',
                "label" => __('loyalty::loyalty_gifts.filters.status'),
                "type" => 'select',
                "options" => LoyaltyGiftStatus::toArrayTrans(),
            ],
            [
                "key" => 'customer_id',
                "label" => __('loyalty::loyalty_gifts.filters.customer'),
                "type" => 'select',
                "options" => User::list(defaultRole: DefaultRole::Customer),
            ],
            [
                "key" => 'loyalty_program_id',
                "label" => __('loyalty::loyalty_gifts.filters.loyalty_program'),
                "type" => 'select',
                "options" => LoyaltyProgram::list(),
            ],
            [
                "key" => 'loyalty_reward_id',
                "label" => __('loyalty::loyalty_gifts.filters.loyalty_reward'),
                "type" => 'select',
                "options" => !is_null($programId) ? LoyaltyReward::list($programId) : [],
                "depends" => "loyalty_program_id"
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function redeem(int $rewardId, int $customerId, array $ctx = []): LoyaltyGift
    {
        $program = $this->resolveProgram($ctx['program_id'] ?? null);
        $tenantId = app(\Modules\Saas\Support\TenantContext::class)->id();

        /** @var LoyaltyCustomer $lc */
        $lc = LoyaltyCustomer::query()
            ->where('customer_id', $customerId)
            ->where('loyalty_program_id', $program->id)
            ->when($tenantId, fn ($query) => $query->whereHas('customer', fn ($customer) => $customer->where('tenant_id', $tenantId)))
            ->lockForUpdate()
            ->firstOrFail();

        $today = now()->toDateString();

        /** @var LoyaltyReward $reward */
        $reward = LoyaltyReward::query()
            ->where('loyalty_program_id', $program->id)
            ->when($tenantId, fn ($query) => $query->where(fn ($q) => $q->whereNull('created_by')
                ->orWhereHas('createdBy', fn ($creator) => $creator->where('tenant_id', $tenantId))))
            ->where(function ($q) use ($today) {
                $q->whereNull('starts_at')
                    ->orWhereDate('starts_at', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('ends_at')
                    ->orWhereDate('ends_at', '>=', $today);
            })
            ->when(
                $lc->loyalty_tier_id,
                fn($q, $tierId) => $q->where(function ($inner) use ($tierId) {
                    $inner->whereNull('loyalty_tier_id')
                        ->orWhere('loyalty_tier_id', $tierId);
                })
            )
            ->lockForUpdate()
            ->findOrFail($rewardId);

        $branchId = auth()->user()->effective_branch->id;

        $conditions = $reward->conditions ?? [];
        $availableDays = $conditions['available_days'] ?? null;
        $branches = $conditions['branch_ids'] ?? null;
        $minSpend = isset($conditions['min_spend']) ? (float)$conditions['min_spend'] : null;

        $earningRate = max(0.000001, $program->earning_rate->amount());
        $lifetimeSpend = $lc->lifetime_points / $earningRate;

        $isAvailableToday = !$availableDays || in_array(strtolower(today()->englishDayOfWeek), $availableDays);
        $isAvailableAtBranch = !$branches || !$branchId || in_array($branchId, $branches);
        $meetsMinSpend = !$minSpend || $lifetimeSpend >= $minSpend;

        abort_unless($isAvailableToday, 403, __('loyalty::messages.reward_not_available_today'));
        abort_unless($isAvailableAtBranch, 403, __('loyalty::messages.reward_not_available_in_branch'));
        abort_unless($meetsMinSpend, 403, __('loyalty::messages.reward_minimum_spend_not_met'));

        $qty = max(1, (int)($ctx['qty'] ?? 1));
        $pointsCost = $reward->points_cost * $qty;

        $this->assertMintLimits($reward, $lc, $qty);

        abort_if($lc->points_balance < $pointsCost, 402, __('loyalty::messages.insufficient_points'));


        return DB::transaction(function () use ($rewardId, $customerId, $qty, $program, $ctx, $lc, $pointsCost, $reward) {

            $remaining = $pointsCost;

            $earns = LoyaltyTransaction::query()
                ->where('loyalty_customer_id', $lc->id)
                ->whereIn('type', [
                    LoyaltyTransactionType::Earn,
                    LoyaltyTransactionType::Bonus,
                ])
                ->whereNull('deleted_at')
                ->where('points', '>', 0)
                ->orderByRaw("COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.valid_until')) AS DATE),'9999-12-31')")
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $usedBySource = LoyaltyTransaction::query()
                ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.source_tx')) as source_tx,ABS(SUM(points)) as used")
                ->where('loyalty_customer_id', $lc->id)
                ->whereIn('type', [
                    LoyaltyTransactionType::Redeem,
                    LoyaltyTransactionType::Expire,
                    LoyaltyTransactionType::Adjust,
                ])
                ->whereRaw("JSON_EXTRACT(meta, '$.source_tx') IS NOT NULL")
                ->groupBy('source_tx')
                ->pluck('used', 'source_tx')
                ->map(fn($v) => (int)$v)
                ->toArray();

            /** @var LoyaltyTransaction $earn */
            foreach ($earns as $earn) {
                if ($remaining <= 0) break;

                $earnId = (int)$earn->id;
                $earned = (int)$earn->points;
                $used = (int)($usedBySource[$earnId] ?? 0);
                $available = $earned - $used;

                if ($available <= 0) continue;

                $consume = min($available, $remaining);

                LoyaltyTransaction::query()->create([
                    'loyalty_customer_id' => $lc->id,
                    'order_id' => null,
                    'type' => LoyaltyTransactionType::Redeem,
                    'points' => -$consume,
                    'amount' => 0,
                    'meta' => [
                        'description' => [
                            'text' => 'loyalty::messages.points_redeemed',
                            'replace' => [
                                'reward_id' => $reward->id,
                                'points' => $consume,
                            ],
                        ],
                        'reward_id' => $reward->id,
                        'source_tx' => $earnId,
                    ],
                ]);

                $remaining -= $consume;
            }

            abort_if($remaining > 0, 402, __('loyalty::messages.insufficient_points'));

            $lc->decrement('points_balance', $pointsCost);

            $artifact = $this->mintArtifact($reward, $customerId);

            $now = Carbon::now();
            $validUntil = null;
            if ($days = Arr::get($reward->meta, 'expires_in_days')) {
                $validUntil = $now->copy()->addDays((int)$days);
            }

            /** @var LoyaltyGift $gift */
            $gift = LoyaltyGift::query()
                ->create([
                    'loyalty_customer_id' => $lc->id,
                    'loyalty_program_id' => $program->id,
                    'loyalty_reward_id' => $reward->id,
                    'artifact_id' => $artifact?->id,
                    'artifact_type' => $artifact ? $artifact->getMorphClass() : null,
                    'type' => $reward->type,
                    'status' => LoyaltyGiftStatus::Available,
                    'qty' => $qty,
                    'points_spent' => $pointsCost,
                    'valid_from' => $now,
                    'valid_until' => $validUntil,
                    'conditions' => $reward->conditions,
                    'meta' => $reward->meta,
                ]);

            $reward->increment('total_redeemed', $qty);

            $this->bumpUniqueCustomerCounter($reward, $lc);

            LoyaltyTransaction::query()
                ->create([
                    'loyalty_customer_id' => $lc->id,
                    'order_id' => null,
                    'type' => LoyaltyTransactionType::Bonus,
                    'points' => 0,
                    'amount' => 0,
                    'meta' => [
                        'description' => [
                            'text' => 'loyalty::messages.gift_created',
                            'replace' => ['gift_id' => $gift->id],
                        ],
                        'reward_id' => $reward->id,
                    ],
                ]);

            if ($gift->type === LoyaltyRewardType::TierUpgrade) {
                $this->useGift(loyaltyGiftId: $gift->id);
            }

            return $gift;
        });
    }

    protected function resolveProgram(?int $programId = null): LoyaltyProgram
    {
        $query = LoyaltyProgram::query();
        $tenantId = app(\Modules\Saas\Support\TenantContext::class)->id();
        if ($tenantId) $query->where(fn ($q) => $q->whereNull('created_by')->orWhereHas('createdBy', fn ($creator) => $creator->where('tenant_id', $tenantId)));
        if ($programId !== null) return $query->findOrFail($programId);

        $program = LoyaltyProgram::currentForTenant($tenantId);
        if (! $program) throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(LoyaltyProgram::class);

        return $program;
    }

    protected function assertMintLimits(LoyaltyReward $reward, LoyaltyCustomer $lc, int $qty): void
    {
        abort_if(
            $reward->usage_limit && $reward->total_redeemed + $qty > $reward->usage_limit,
            429,
            __('loyalty::messages.reward_usage_limit')
        );


        if ($reward->per_customer_limit) {
            $used = LoyaltyGift::query()
                ->where('loyalty_reward_id', $reward->id)
                ->where('loyalty_customer_id', $lc->id)
                ->count();

            abort_if(
                $used + $qty > $reward->per_customer_limit,
                409,
                __('loyalty::messages.reward_customer_limit')
            );

        }
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        $tenantId = app(\Modules\Saas\Support\TenantContext::class)->id();
        return $this->getModel()
            ->query()
            ->when($tenantId, fn ($query) => $query->whereHas('customer.customer', fn ($customer) => $customer->where('tenant_id', $tenantId)))
            ->with([
                "customer.customer:id,name",
                "program:id,name",
                "reward:id,name"
            ])
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): LoyaltyGift
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyGift::class;
    }

    /**
     * @throws RandomException
     */
    protected function mintArtifact(LoyaltyReward $reward, int $customerId)
    {
        switch ($reward->type) {
            case LoyaltyRewardType::Discount:
                $expiresInDays = Arr::get($reward->meta, 'expires_in_days');
                return Discount::query()
                    ->create([
                        'name' => $reward->name,
                        'description' => $reward->description,
                        'type' => $reward->value_type,
                        'value' => $reward->value,
                        'is_active' => true,
                        'minimum_spend' => Arr::get($reward->meta, 'min_order_total'),
                        'maximum_spend' => Arr::get($reward->meta, 'max_order_total'),
                        'usage_limit' => 1,
                        'per_customer_limit' => 1,
                        'max_discount' => Arr::get($reward->meta, 'max_discount'),
                        'start_date' => now(),
                        'end_date' => is_null($expiresInDays) ? null : now()->addDays($expiresInDays),
                        'meta' => [
                            'reward_id' => $reward->id,
                            'customer_id' => $customerId
                        ]
                    ]);
            case LoyaltyRewardType::VoucherCode:
                $expiresInDays = Arr::get($reward->meta, 'expires_in_days');
                $code = strtoupper(Arr::get($reward->meta, 'code_prefix', 'LOY') . '-' . bin2hex(random_bytes(3)));
                return Voucher::query()
                    ->create([
                        'name' => $reward->name,
                        'description' => $reward->description,
                        'code' => $code,
                        'type' => $reward->value_type,
                        'value' => $reward->value,
                        'is_active' => true,
                        'minimum_spend' => Arr::get($reward->meta, 'min_order_total'),
                        'maximum_spend' => Arr::get($reward->meta, 'max_order_total'),
                        'usage_limit' => 1,
                        'per_customer_limit' => 1,
                        'max_discount' => Arr::get($reward->meta, 'max_discount'),
                        'start_date' => now(),
                        'end_date' => is_null($expiresInDays) ? null : now()->addDays($expiresInDays),
                        'meta' => [
                            'reward_id' => $reward->id,
                            'customer_id' => $customerId
                        ]
                    ]);
            default:
                return null;
        }
    }

    protected function bumpUniqueCustomerCounter(LoyaltyReward $reward, LoyaltyCustomer $lc): void
    {
        $exists = LoyaltyGift::query()
            ->where('loyalty_reward_id', $reward->id)
            ->where('loyalty_customer_id', $lc->id)
            ->exists();

        if (!$exists) {
            $reward->increment('total_customers');
        }
    }

    /** @inheritDoc */
    public function useGift(int $loyaltyGiftId, ?Order $order = null): void
    {
        $gift = LoyaltyGift::query()
            ->with('customer')
            ->lockForUpdate()
            ->findOrFail($loyaltyGiftId);

        abort_if(
            $gift->status !== LoyaltyGiftStatus::Available,
            404,
            __('loyalty::messages.gift_not_available')
        );

        if (!is_null($order)) {
            $this->assertUsableOnOrder($gift, $order);
        }

        DB::transaction(function () use ($gift, $order) {
            switch ($gift->type) {
                case LoyaltyRewardType::TierUpgrade:
                    $gift->customer
                        ->update([
                            "loyalty_tier_id" => $gift->meta['target_tier'],
                            "last_redeemed_at" => now(),
                            "force" => true
                        ]);
                    break;
                default:
                    $gift->customer->update(["last_redeemed_at" => now()]);
            }

            $gift->update([
                'status' => LoyaltyGiftStatus::Used,
                'used_at' => Carbon::now(),
                'meta' => array_merge($gift->meta ?? [], ['used_order_id' => $order?->id]),
            ]);

            LoyaltyTransaction::query()
                ->create([
                    'loyalty_customer_id' => $gift->loyalty_customer_id,
                    'order_id' => $order?->id,
                    'type' => LoyaltyTransactionType::Adjust,
                    'points' => 0,
                    'amount' => 0,
                    'meta' => [
                        'description' => [
                            'text' => 'loyalty::messages.gift_used',
                            'replace' => ['gift_id' => $gift->id],
                        ],
                    ],
                ]);
        });
    }

    protected function assertUsableOnOrder(LoyaltyGift $gift, Order $order): void
    {
        $conditions = $gift->conditions ?? [];

        if (!empty($conditions['available_days'])) {
            abort_unless(
                in_array(strtolower(now()->englishDayOfWeek), $conditions['available_days']),
                400,
                __('loyalty::messages.gift_not_valid_today')
            );
        }

        abort_if(
            !empty($conditions['branch_ids']) && !in_array($order->branch_id, $conditions['branch_ids']),
            400,
            __('loyalty::messages.gift_not_valid_branch')
        );

        abort_if(
            isset($conditions['min_order_total']) && $order->total->amount() < (float)$conditions['min_order_total'],
            400,
            __('loyalty::messages.gift_min_spend')
        );
    }

    /** @inheritDoc */
    public function availableGifts(int $customerId, ?int $programId = null): Collection
    {
        $tenantId = app(\Modules\Saas\Support\TenantContext::class)->id();
        User::query()->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))->findOrFail($customerId);
        $program = $this->resolveProgram($programId);

        $lc = LoyaltyCustomer::query()
            ->where('customer_id', $customerId)
            ->where('loyalty_program_id', $program->id)
            ->first();

        if (is_null($lc)) {
            return collect();
        }

        $now = Carbon::now();

        return LoyaltyGift::query()
            ->with(['reward' => fn($query) => $query->with('files')])
            ->where('loyalty_customer_id', $lc->id)
            ->where('status', LoyaltyGiftStatus::Available)
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $now);
            })
            ->get();
    }

    /** @inheritDoc */
    public function expireGifts(int $batch = 100): int
    {
        $now = Carbon::now();
        $expired = 0;

        $gifts = LoyaltyGift::query()
            ->where('status', LoyaltyGiftStatus::Available)
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', $now)
            ->limit($batch)
            ->get();

        /** @var LoyaltyGift $gift */
        foreach ($gifts as $gift) {
            DB::transaction(function () use ($gift, &$expired) {
                $gift = LoyaltyGift::query()->lockForUpdate()->find($gift->id);
                if (!$gift || $gift->status !== LoyaltyGiftStatus::Available) {
                    return;
                }

                if ($gift->artifact) {
                    $gift->artifact->update(['is_active' => false]);
                }

                $gift->update(['status' => LoyaltyGiftStatus::Expired]);

                LoyaltyTransaction::query()
                    ->create([
                        'loyalty_customer_id' => $gift->loyalty_customer_id,
                        'order_id' => null,
                        'type' => LoyaltyTransactionType::Expire,
                        'points' => 0,
                        'amount' => 0,
                        'meta' => [
                            'description' => [
                                'text' => 'loyalty::messages.gift_expired',
                                'replace' => ['gift_id' => $gift->id],
                            ],
                            'reason' => 'gift_expired'
                        ],
                    ]);

                $expired++;
            });
        }

        return $expired;
    }

    /** @inheritDoc */
    public function getRewards(int $customerId, ?int $programId = null, ?int $branchId = null): array
    {
        $tenantId = app(\Modules\Saas\Support\TenantContext::class)->id();
        User::query()->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))->findOrFail($customerId);
        $program = $this->resolveProgram($programId);

        /** @var LoyaltyCustomer|null $lc */
        $lc = LoyaltyCustomer::query()
            ->with(["customer", "loyaltyTier"])
            ->where('customer_id', $customerId)
            ->where('loyalty_program_id', $program->id)
            ->first();

        if (!$lc) {
            $lc = DB::transaction(function () use ($customerId, $program) {
                $tenantId = app(\Modules\Saas\Support\TenantContext::class)->id();
                User::query()->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
                    ->lockForUpdate()->findOrFail($customerId);
                return LoyaltyCustomer::query()->firstOrCreate(
                    ['customer_id' => $customerId, 'loyalty_program_id' => $program->id],
                    ['points_balance' => 0, 'lifetime_points' => 0]
                );
            });
            $lc->load(['customer', 'loyaltyTier']);
        }

        $pointsBalance = $lc->points_balance;
        $today = now()->toDateString();

        $tenantId = app(\Modules\Saas\Support\TenantContext::class)->id();
        $rewards = LoyaltyReward::query()
            ->with('files')
            ->when($tenantId, fn ($query) => $query->where(fn ($q) => $q->whereNull('created_by')
                ->orWhereHas('createdBy', fn ($creator) => $creator->where('tenant_id', $tenantId))))
            ->where('loyalty_program_id', $program->id)
            ->where(function ($q) use ($today) {
                $q->whereNull('starts_at')
                    ->orWhereDate('starts_at', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('ends_at')
                    ->orWhereDate('ends_at', '>=', $today);
            })
            ->when(
                $lc->loyalty_tier_id,
                fn($q, $tierId) => $q->where(function ($inner) use ($tierId) {
                    $inner->whereNull('loyalty_tier_id')
                        ->orWhere('loyalty_tier_id', $tierId);
                })
            )
            ->get();

        $customerInfo = [
            "id" => $lc->customer_id,
            "name" => $lc->customer->name,
            "phone" => $lc->customer->phone,
            "points_balance" => $lc->points_balance,
            "points_balance_format" => $lc->points_balance_format,
            "tier" => [
                "id" => $lc->loyaltyTier?->id,
                "name" => $lc->loyaltyTier?->name,
                'icon' => $lc->loyaltyTier?->icon != null ? new MediaSimpleResource($lc->loyaltyTier?->icon) : null,
            ]
        ];

        if ($rewards->isEmpty()) {
            return [
                "eligible" => [],
                "customer" => $customerInfo
            ];
        }

        $usageCounts = LoyaltyGift::query()
            ->selectRaw('loyalty_reward_id, COUNT(*) as used_count')
            ->whereIn('loyalty_reward_id', $rewards->pluck('id'))
            ->groupBy('loyalty_reward_id')
            ->pluck('used_count', 'loyalty_reward_id')
            ->toArray();

        $customerCounts = LoyaltyGift::query()
            ->selectRaw('loyalty_reward_id, COUNT(*) as used_count')
            ->whereIn('loyalty_reward_id', $rewards->pluck('id'))
            ->where('loyalty_customer_id', $lc->id)
            ->groupBy('loyalty_reward_id')
            ->pluck('used_count', 'loyalty_reward_id')
            ->toArray();

        $eligible = [];

        $earningRate = max(0.000001, $program->earning_rate->amount());
        $lifetimeSpend = $lc->lifetime_points / $earningRate;

        /** @var LoyaltyReward $reward */
        foreach ($rewards as $reward) {
            $conditions = $reward->conditions ?? [];
            $availableDays = $conditions['available_days'] ?? null;
            $minSpend = isset($conditions['min_spend']) ? (float)$conditions['min_spend'] : null;
            $meetsMinSpend = !$minSpend || $lifetimeSpend >= $minSpend;
            $branches = $conditions['branch_ids'] ?? null;

            $isAvailableToday = !$availableDays || in_array(strtolower(now()->englishDayOfWeek), $availableDays);
            $isAvailableAtBranch = !$branches || !$branchId || in_array($branchId, $branches);

            if (!$isAvailableAtBranch) {
                continue;
            }

            $hasPoints = $pointsBalance >= (int)$reward->points_cost;

            $usedTotal = (int)($usageCounts[$reward->id] ?? 0);
            $usedCustomer = (int)($customerCounts[$reward->id] ?? 0);

            $remainingGlobal = !is_null($reward->usage_limit)
                ? max(0, (int)$reward->usage_limit - $usedTotal)
                : PHP_INT_MAX;

            $remainingCustomer = !is_null($reward->per_customer_limit)
                ? max(0, (int)$reward->per_customer_limit - $usedCustomer)
                : PHP_INT_MAX;

            $isEligible = $isAvailableToday
                && $hasPoints
                && $meetsMinSpend
                && $remainingGlobal > 0
                && $remainingCustomer > 0;

            $maxRedemptions = min(
                floor($pointsBalance / $reward->points_cost),
                $remainingCustomer,
                $remainingGlobal,
                $reward->max_redemptions_per_order ?? PHP_INT_MAX
            );

            $eligible[] = [
                'id' => $reward->id,
                'name' => $reward->name,
                'icon' => $reward->icon != null ? new MediaSimpleResource($reward->icon) : null,
                'description' => $reward->description,
                'type' => $reward->type,
                'points_cost' => (int)$reward->points_cost,
                'max_redemptions' => $maxRedemptions,
                'is_eligible' => $isEligible,
            ];
        }

        return [
            "eligible" => $eligible,
            "customer" => $customerInfo
        ];
    }

    /** @inheritDoc */
    public function availableGift(int $customerId, int $giftId, ?int $branchId = null): ?LoyaltyGift
    {
        $tenantId = app(\Modules\Saas\Support\TenantContext::class)->id();
        $now = Carbon::now();
        $gift = LoyaltyGift::query()
            ->with("artifact")
            ->where('id', $giftId)
            ->whereHas('customer', fn($query) => $query->where('customer_id', $customerId))
            ->when($tenantId, fn ($query) => $query->whereHas('customer.customer', fn ($customer) => $customer->where('tenant_id', $tenantId)))
            ->where('status', LoyaltyGiftStatus::Available)
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $now);
            })
            ->first();


        if (!$gift) return null;

        $conditions = $gift->conditions ?? [];
        $availableDays = $conditions['available_days'] ?? null;
        $branches = $conditions['branch_ids'] ?? null;

        $isAvailableToday = !$availableDays || in_array(strtolower(now()->englishDayOfWeek), $availableDays);
        $isAvailableAtBranch = !$branches || !$branchId || in_array($branchId, $branches);

        return $isAvailableToday && $isAvailableAtBranch ? $gift : null;

    }

    /** @inheritDoc */
    public function rollbackGift(int $loyaltyGiftId, Order $order): void
    {
        $gift = LoyaltyGift::query()
            ->lockForUpdate()
            ->findOrFail($loyaltyGiftId);

        if ($gift->status === LoyaltyGiftStatus::Used) {
            DB::transaction(function () use ($gift, $order) {

                $updatedMeta = $gift->meta ?? [];
                unset($updatedMeta['used_order_id']);

                $gift->update([
                    'status' => LoyaltyGiftStatus::Available,
                    'used_at' => null,
                    'meta' => $updatedMeta,
                ]);

                LoyaltyTransaction::query()->create([
                    'loyalty_customer_id' => $gift->loyalty_customer_id,
                    'order_id' => $order->id,
                    'type' => LoyaltyTransactionType::Adjust,
                    'points' => 0,
                    'amount' => 0,
                    'meta' => [
                        'description' => [
                            'text' => 'loyalty::messages.gift_rollback',
                            'replace' => ['gift_id' => $gift->id],
                        ],
                    ],
                ]);
            });
        }
    }
}
