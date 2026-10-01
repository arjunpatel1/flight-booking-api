<?php

namespace Modules\Voucher\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Branch\Models\Branch;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\Support\InputLimit;
use Modules\Voucher\Models\GiftCard;

class GiftCardController extends Controller
{
    /**
     * Display a listing of gift cards.
     */
    public function index(Request $request): JsonResponse
    {
        $query = GiftCard::query()
            ->filters($request->get('filters', []))
            ->sortBy($request->get('sorts', []))
            ->with(['customer:id,name', 'branch:id,name']);

        return ApiResponse::pagination(
            paginator: $query->paginate($request->input('per_page', 15)),
        );
    }

    /**
     * Return creation metadata from the voucher boundary.
     *
     * This intentionally avoids the generic cached Branch::list() helper so
     * the gift-card dialog cannot show stale or cross-tenant branch options.
     */
    public function getFormMeta(Request $request): JsonResponse
    {
        $actor = $request->user();

        $branches = Branch::query()
            ->select(['id', 'name', 'currency', 'tenant_id', 'is_main'])
            ->when(
                $actor?->assignedToTenant() && ! $actor->isSuperAdmin(),
                fn ($query) => $query->where('tenant_id', $actor->tenantId())
            )
            ->when(
                $actor?->assignedToBranch(),
                fn ($query) => $query->whereKey($actor->branch_id)
            )
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
                'currency' => $branch->currency,
                'tenant_id' => $branch->tenant_id,
            ])
            ->values();

        return ApiResponse::success([
            'branches' => $branches,
        ]);
    }

    /**
     * Store a newly created gift card.
     */
    public function store(Request $request): JsonResponse
    {
        $branchId = $request->integer('branch_id');
        $tenantId = Branch::query()
            ->withoutGlobalScopes()
            ->whereKey($branchId)
            ->value('tenant_id');

        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
            'code' => [
                'required',
                'string',
                'max:64',
                Rule::unique('gift_cards', 'code')->where('branch_id', $branchId),
            ],
            'initial_balance' => ['required', 'numeric', 'min:0'],
            'status' => ['nullable', 'string', 'in:active,inactive,expired'],
            'issued_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after:issued_at'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->authorizeBranch($request, (int) $data['branch_id']);

        $data['current_balance'] = $data['initial_balance'];
        $data['created_by'] = auth()->id();

        $giftCard = GiftCard::create($data);

        return ApiResponse::success($giftCard->load('customer:id,name'));
    }

    /**
     * Display the specified gift card.
     */
    public function show(int $id): JsonResponse
    {
        $giftCard = GiftCard::with(['customer:id,name', 'transactions'])->findOrFail($id);

        return ApiResponse::success($giftCard);
    }

    /**
     * Update the specified gift card.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $giftCard = GiftCard::query()->select(['id', 'branch_id'])->findOrFail($id);
        $tenantId = Branch::query()
            ->withoutGlobalScopes()
            ->whereKey($giftCard->branch_id)
            ->value('tenant_id');

        $data = $request->validate([
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
            'status' => ['nullable', 'string', 'in:active,inactive,expired'],
            'expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $giftCard = DB::transaction(function () use ($id, $data): GiftCard {
            $giftCard = GiftCard::query()->lockForUpdate()->findOrFail($id);
            $giftCard->update($data);

            return $giftCard;
        });

        return ApiResponse::success($giftCard->load('customer:id,name'));
    }

    /**
     * Remove the specified gift card.
     */
    public function destroy(string $ids): JsonResponse
    {
        $ids = explode(',', $ids);
        $deleted = GiftCard::whereIn('id', $ids)->delete() > 0;

        return ApiResponse::destroyed(
            destroyed: $deleted,
            resource: __('voucher::vouchers.gift_card')
        );
    }

    /**
     * Redeem balance from a gift card.
     */
    public function redeem(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', ...InputLimit::money(min: 0.01)],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $giftCard = DB::transaction(function () use ($id, $data) {
            $giftCard = GiftCard::query()->lockForUpdate()->findOrFail($id);
            abort_if(!$giftCard->hasSufficientBalance($data['amount']), 422, __('voucher::messages.insufficient_balance'));

            $newBalance = (float) $giftCard->current_balance - (float) $data['amount'];

            $giftCard->update([
                'current_balance' => $newBalance,
                'total_used' => (float) $giftCard->total_used + (float) $data['amount'],
            ]);

            $giftCard->transactions()->create([
                'order_id' => $data['order_id'] ?? null,
                'type' => 'redeem',
                'amount' => $data['amount'],
                'balance_after' => $newBalance,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            return $giftCard;
        });

        return ApiResponse::success($giftCard->load('transactions'));
    }

    /**
     * Top up a gift card.
     */
    public function topUp(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', ...InputLimit::money(min: 0.01)],
            'notes' => ['nullable', 'string'],
        ]);

        $giftCard = DB::transaction(function () use ($id, $data) {
            $giftCard = GiftCard::query()->lockForUpdate()->findOrFail($id);
            $newBalance = (float) $giftCard->current_balance + (float) $data['amount'];

            $giftCard->update([
                'current_balance' => $newBalance,
            ]);

            $giftCard->transactions()->create([
                'type' => 'top_up',
                'amount' => $data['amount'],
                'balance_after' => $newBalance,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            return $giftCard;
        });

        return ApiResponse::success($giftCard->load('transactions'));
    }

    /**
     * Get gift card analytics.
     */
    public function analytics(Request $request): JsonResponse
    {
        $branchId = $request->input('branch_id');
        $from = $request->input('from');
        $to = $request->input('to');

        $query = GiftCard::query()
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($from, fn($q) => $q->whereDate('issued_at', '>=', $from))
            ->when($to, fn($q) => $q->whereDate('issued_at', '<=', $to));

        $totalIssued = (clone $query)->count();
        $totalInitialBalance = (clone $query)->sum('initial_balance');
        $totalCurrentBalance = (clone $query)->sum('current_balance');
        $totalUsed = (clone $query)->sum('total_used');
        $activeCount = (clone $query)->where('status', 'active')->count();
        $expiredCount = (clone $query)
            ->where(fn($statusQuery) => $statusQuery
                ->where('status', 'expired')
                ->orWhere(fn($dateQuery) => $dateQuery
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', now())))
            ->count();

        return ApiResponse::success([
            'total_issued' => $totalIssued,
            'total_initial_balance' => $totalInitialBalance,
            'total_current_balance' => $totalCurrentBalance,
            'total_used' => $totalUsed,
            'active_count' => $activeCount,
            'expired_count' => $expiredCount,
        ]);
    }

    private function authorizeBranch(Request $request, int $branchId): void
    {
        $actor = $request->user();
        $branch = Branch::query()
            ->select(['id', 'tenant_id'])
            ->findOrFail($branchId);

        abort_if(
            $actor?->assignedToTenant()
            && ! $actor->isSuperAdmin()
            && (int) $branch->tenant_id !== (int) $actor->tenantId(),
            403,
            'Branch is not available for this restaurant.'
        );

        abort_if(
            $actor?->assignedToBranch() && (int) $branch->id !== (int) $actor->branch_id,
            403,
            'Branch is not available for this user.'
        );
    }
}
