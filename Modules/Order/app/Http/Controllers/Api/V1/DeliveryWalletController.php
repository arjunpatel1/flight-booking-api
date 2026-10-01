<?php

namespace Modules\Order\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Order\Delivery\DeliveryWallet;
use Modules\Order\Delivery\DeliveryCommercialTerms;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;
use Modules\Saas\Support\TenantContext;
use Modules\Support\ApiResponse;

class DeliveryWalletController extends Controller
{
    public function show(Request $request, DeliveryWallet $wallet, TenantContext $context): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', Rule::in(['credit', 'debit', 'reserve', 'release', 'capture', 'refund'])],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $tenantId = $this->restaurantTenantId($request, $context);
        return ApiResponse::success($this->payload($request, $wallet, $tenantId));
    }

    public function showForPlatform(Request $request, int $tenantId, DeliveryWallet $wallet,
        EffectiveTenantEntitlementService $entitlements): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', Rule::in(['credit', 'debit', 'reserve', 'release', 'capture', 'refund'])],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $tenant = Tenant::query()->withoutGlobalScopes()->findOrFail($tenantId);
        abort_unless($entitlements->has($tenant, 'delivery'), 404);
        return ApiResponse::success($this->payload($request, $wallet, $tenantId, true));
    }

    private function payload(Request $request, DeliveryWallet $wallet, int $tenantId, bool $includeActor = false): array
    {
        abort_unless(
            Schema::hasTable('delivery_wallet_accounts') && Schema::hasTable('delivery_wallet_transactions'),
            503,
            'Delivery wallet storage is not installed. Run the pending database migrations before using this feature.'
        );
        $account = $wallet->account($tenantId);
        $from = $request->date('from')?->startOfDay();
        $to = $request->date('to')?->endOfDay();
        $query = DB::table('delivery_wallet_transactions as wallet_entries')
            ->leftJoin('orders', 'orders.id', '=', 'wallet_entries.order_id')
            ->when($includeActor, fn ($q) => $q->leftJoin('users as actors', 'actors.id', '=', 'wallet_entries.actor_id'))
            ->where('wallet_entries.tenant_id', $tenantId)
            ->when($from, fn ($q) => $q->where('wallet_entries.created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('wallet_entries.created_at', '<=', $to))
            ->when($request->filled('type'), fn ($q) => $q->where('wallet_entries.type', $request->string('type')->toString()))
            ->when($request->filled('search'), function ($q) use ($request): void {
                $search = str_replace(['%', '_'], ['\%', '\_'], trim($request->string('search')->toString()));
                $q->where(function ($filter) use ($search): void {
                    $filter->where('orders.reference_no', 'like', "%{$search}%")
                        ->orWhere('wallet_entries.reference', 'like', "%{$search}%")
                        ->orWhere('wallet_entries.reason', 'like', "%{$search}%");
                });
            });
        $totals = (clone $query)->selectRaw("SUM(CASE WHEN wallet_entries.type = 'credit' THEN wallet_entries.amount ELSE 0 END) credits")
            ->selectRaw("SUM(CASE WHEN wallet_entries.type IN ('debit','capture') THEN wallet_entries.amount ELSE 0 END) debits")
            ->selectRaw("SUM(CASE WHEN wallet_entries.type = 'reserve' THEN wallet_entries.amount ELSE 0 END) reserved")
            ->selectRaw("SUM(CASE WHEN wallet_entries.type = 'release' THEN wallet_entries.amount ELSE 0 END) released")
            ->selectRaw("SUM(CASE WHEN wallet_entries.type = 'refund' THEN wallet_entries.amount ELSE 0 END) refunds")->first();
        $perPage = min(max((int) $request->integer('limit', 10), 1), 100);
        $requestedPage = max($request->integer('page', 1), 1);
        $transactionCount = (clone $query)->count('wallet_entries.id');
        $lastPage = max(1, (int) ceil($transactionCount / $perPage));
        $page = min($requestedPage, $lastPage);
        $transactions = $query->select(['wallet_entries.*', 'orders.reference_no as order_reference',
            ...($includeActor ? ['actors.name as actor_name'] : [])])
            ->orderByDesc('wallet_entries.id')->offset(($page - 1) * $perPage)->limit($perPage)->get()->map(fn ($row) => [
            'uuid' => $row->uuid, 'type' => $row->type, 'status' => $row->status,
            'amount' => $row->amount, 'available_after' => $row->available_after,
            'reserved_after' => $row->reserved_after, 'order_id' => $row->order_id,
            'delivery_id' => $row->order_delivery_id, 'reference' => $row->reference,
            'order_reference' => $row->order_reference,
            ...($includeActor ? ['actor_name' => $row->actor_name] : []),
            'reason' => $row->reason,
            'metadata' => filled($row->metadata) ? json_decode($row->metadata, true) : null,
            'created_at' => $row->created_at,
        ]);
        $deliveryReport = DB::table('order_deliveries')->where('tenant_id', $tenantId)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->selectRaw('COALESCE(SUM(customer_delivery_fee), 0) customer_delivery_fees')
            ->selectRaw('COALESCE(SUM(COALESCE(provider_final_cost, provider_quoted_cost, 0)), 0) provider_delivery_costs')
            ->selectRaw('COALESCE(SUM(restaurant_contribution), 0) restaurant_contribution')
            ->selectRaw('COALESCE(SUM(platform_contribution), 0) platform_contribution')
            ->selectRaw('COALESCE(SUM(delivery_margin), 0) delivery_difference')
            ->first();

        return [
            'account' => [
                'currency' => $account->currency, 'available_balance' => $account->available_balance,
                'reserved_balance' => $account->reserved_balance,
                'low_balance_threshold' => $account->low_balance_threshold,
                'block_booking_when_insufficient' => (bool) $account->block_booking_when_insufficient,
                'is_low_balance' => bccomp((string) $account->available_balance, (string) $account->low_balance_threshold, 4) <= 0,
            ],
            'report' => ['credits' => $totals->credits ?? '0', 'debits' => $totals->debits ?? '0',
                'reserved' => $totals->reserved ?? '0', 'released' => $totals->released ?? '0',
                'refunds' => $totals->refunds ?? '0',
                'customer_delivery_fees' => $deliveryReport->customer_delivery_fees ?? '0',
                'provider_delivery_costs' => $deliveryReport->provider_delivery_costs ?? '0',
                'restaurant_contribution' => $deliveryReport->restaurant_contribution ?? '0',
                'platform_contribution' => $deliveryReport->platform_contribution ?? '0',
                'delivery_difference' => $deliveryReport->delivery_difference ?? '0',
                'platform_delivery_fees' => $deliveryReport->delivery_difference ?? '0'],
            'transactions' => $transactions,
            'transaction_pagination' => ['page' => $page, 'per_page' => $perPage,
                'total' => $transactionCount, 'last_page' => $lastPage,
                'from' => $transactionCount ? (($page - 1) * $perPage) + 1 : null,
                'to' => $transactionCount ? min($page * $perPage, $transactionCount) : null],
            'commercial_terms' => [
                'wallet_gst_rate' => app(DeliveryCommercialTerms::class)->walletGstRate(),
                'platform_fee_per_order' => app(DeliveryCommercialTerms::class)->platformFeePerOrder(),
            ],
            'funding' => ['status' => 'blocked', 'message' => 'Online wallet top-up requires a verified payment gateway flow. Contact the NexDine platform operator to add funds.'],
        ];
    }

    public function update(Request $request, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'low_balance_threshold' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'block_booking_when_insufficient' => ['required', 'accepted'],
        ]);
        $tenantId = $this->restaurantTenantId($request, $context);
        app(DeliveryWallet::class)->account($tenantId);
        DB::table('delivery_wallet_accounts')->where('tenant_id', $tenantId)->update([
            ...$data, 'updated_at' => now(),
        ]);
        return ApiResponse::success(null, 'Delivery wallet controls updated.');
    }

    /** Platform-only audited adjustment. Tenants cannot mint their own wallet balance. */
    public function adjust(Request $request, int $tenantId, DeliveryWallet $wallet,
        EffectiveTenantEntitlementService $entitlements): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['credit', 'debit'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $tenant = Tenant::query()->withoutGlobalScopes()->findOrFail($tenantId);
        abort_unless($entitlements->has($tenant, 'delivery'), 404);
        $tax = $data['type'] === 'credit'
            ? app(DeliveryCommercialTerms::class)->walletTopUp((float) $data['amount']) : null;
        $transaction = $wallet->adjust($tenantId, $data['type'], $data['amount'], $data['idempotency_key'],
            $data['reason'], $request->user()?->id, $tax === null ? null : ['wallet_top_up' => $tax]);
        if (! $transaction->_idempotent_replay) {
            activity('delivery_wallet')->event('delivery_wallet_adjusted')->causedBy($request->user())
                ->withProperties(['tenant_id' => $tenantId, 'transaction_uuid' => $transaction->uuid,
                    'type' => $transaction->type, 'amount' => $transaction->amount])->log('Delivery wallet balance adjusted.');
        }
        $postedMetadata = filled($transaction->metadata) ? json_decode($transaction->metadata, true) : [];

        return ApiResponse::success([
            'transaction_uuid' => $transaction->uuid,
            'idempotent_replay' => (bool) $transaction->_idempotent_replay,
            'top_up' => data_get($postedMetadata, 'wallet_top_up', $tax),
        ], $transaction->_idempotent_replay ? 'Delivery wallet adjustment already posted.' : 'Delivery wallet adjustment posted.');
    }

    /** Platform-only reversal after the provider confirms a delivery-cost refund. */
    public function refund(Request $request, int $tenantId, DeliveryWallet $wallet,
        EffectiveTenantEntitlementService $entitlements): JsonResponse
    {
        $data = $request->validate([
            'delivery_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'provider_reference' => ['required', 'string', 'max:191'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $tenant = Tenant::query()->withoutGlobalScopes()->findOrFail($tenantId);
        abort_unless($entitlements->has($tenant, 'delivery'), 404);
        $delivery = DB::table('order_deliveries')->where('tenant_id', $tenantId)
            ->where('id', $data['delivery_id'])->first();
        abort_unless($delivery, 404);
        $transaction = $wallet->refundCapture($tenantId, (int) $delivery->id, (int) $delivery->order_id,
            $data['amount'], $data['idempotency_key'], $data['reason'], $data['provider_reference'], $request->user()?->id);
        if (! $transaction->_idempotent_replay) {
            activity('delivery_wallet')->event('delivery_wallet_provider_refund')->causedBy($request->user())
                ->withProperties(['tenant_id' => $tenantId, 'delivery_id' => $delivery->id,
                    'transaction_uuid' => $transaction->uuid, 'amount' => $transaction->amount,
                    'provider_reference' => $data['provider_reference']])->log('Provider delivery refund credited to wallet.');
        }
        return ApiResponse::success(['transaction_uuid' => $transaction->uuid,
            'idempotent_replay' => (bool) $transaction->_idempotent_replay],
            $transaction->_idempotent_replay ? 'Provider refund already posted.' : 'Provider refund credited.');
    }

    private function restaurantTenantId(Request $request, TenantContext $context): int
    {
        $actor = $request->user();
        abort_unless($actor, 401);
        $actorTenantId = (int) ($actor?->tenantId() ?: 0);
        $contextTenantId = (int) ($context->id() ?: 0);
        if ($actor?->assignedToTenant()) {
            abort_unless($actorTenantId > 0, 403, 'A restaurant context is required.');
            abort_if($contextTenantId > 0 && $contextTenantId !== $actorTenantId, 404);
            return $actorTenantId;
        }

        abort_unless($contextTenantId > 0, 403, 'A restaurant context is required.');
        return $contextTenantId;
    }
}
