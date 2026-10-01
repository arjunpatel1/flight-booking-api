<?php

namespace Modules\Pos\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Http\Controllers\Controller;
use Modules\Pos\Models\CustomerDisplaySession;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class CustomerDisplaySessionController extends Controller
{
    public function access(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cart_id' => ['required', 'uuid'],
            'branch_id' => ['nullable', 'integer'],
        ]);
        $actor = $request->user();
        $tenantId = $actor?->tenantId();
        abort_if(!$tenantId, Response::HTTP_FORBIDDEN);

        $branchId = $this->authorizedBranchId($tenantId, $actor?->branchId(), $data['branch_id'] ?? null);
        $token = Str::random(64);
        $expiresAt = now()->addHours(8);

        CustomerDisplaySession::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'cart_id' => $data['cart_id']],
            [
                'branch_id' => $branchId,
                'access_token_hash' => hash('sha256', $token),
                'expires_at' => $expiresAt,
                'closed_at' => null,
            ],
        );

        return ApiResponse::success([
            'cart_id' => $data['cart_id'],
            'access_token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function publish(Request $request, string $cartId): JsonResponse
    {
        $data = $request->validate([
            'snapshot' => ['required', 'array'],
            'snapshot.cartId' => ['required', 'uuid'],
            'snapshot.updatedAt' => ['required', 'date'],
            'snapshot.orderType' => ['nullable', 'string', 'max:120'],
            'snapshot.tableName' => ['nullable', 'string', 'max:120'],
            'snapshot.customerName' => ['nullable', 'string', 'max:190'],
            'snapshot.items' => ['required', 'array', 'max:250'],
            'snapshot.items.*.id' => ['required', 'string', 'max:190'],
            'snapshot.items.*.name' => ['required', 'string', 'max:255'],
            'snapshot.items.*.qty' => ['required', 'numeric', 'min:0', 'max:99999'],
            'snapshot.items.*.options' => ['required', 'array', 'max:100'],
            'snapshot.items.*.options.*' => ['string', 'max:255'],
            'snapshot.items.*.unitPrice' => ['required', 'string', 'max:80'],
            'snapshot.items.*.total' => ['required', 'string', 'max:80'],
            'snapshot.quantity' => ['required', 'numeric', 'min:0', 'max:99999'],
            'snapshot.subTotal' => ['required', 'string', 'max:80'],
            'snapshot.taxes' => ['required', 'array', 'max:100'],
            'snapshot.taxes.*.id' => ['required', 'integer'],
            'snapshot.taxes.*.name' => ['required', 'string', 'max:190'],
            'snapshot.taxes.*.amount' => ['required', 'string', 'max:80'],
            'snapshot.discount' => ['nullable', 'array'],
            'snapshot.discount.name' => ['nullable', 'string', 'max:190'],
            'snapshot.discount.value' => ['required_with:snapshot.discount', 'string', 'max:80'],
            'snapshot.total' => ['required', 'string', 'max:80'],
        ]);
        $actor = $request->user();
        $tenantId = $actor?->tenantId();
        abort_if(!$tenantId, Response::HTTP_FORBIDDEN);

        $session = CustomerDisplaySession::query()
            ->where('tenant_id', $tenantId)
            ->where('cart_id', $cartId)
            ->whereNull('closed_at')
            ->firstOrFail();

        $snapshot = $data['snapshot'];
        abort_unless(hash_equals($cartId, (string) ($snapshot['cartId'] ?? '')), Response::HTTP_UNPROCESSABLE_ENTITY);

        $session->update([
            'snapshot' => $snapshot,
            'last_published_at' => now(),
            'expires_at' => now()->addHours(8),
        ]);

        return ApiResponse::success(['published_at' => $session->last_published_at?->toIso8601String()]);
    }

    public function snapshot(Request $request, string $cartId): JsonResponse
    {
        $token = (string) $request->query('token');
        abort_if(strlen($token) < 40, Response::HTTP_UNAUTHORIZED);

        $session = CustomerDisplaySession::query()
            ->withoutGlobalTenant()
            ->where('cart_id', $cartId)
            ->where('access_token_hash', hash('sha256', $token))
            ->whereNull('closed_at')
            ->where('expires_at', '>', now())
            ->first();

        abort_if(!$session, Response::HTTP_UNAUTHORIZED);

        return ApiResponse::success([
            'snapshot' => $session->snapshot,
            'published_at' => $session->last_published_at?->toIso8601String(),
            'expires_at' => $session->expires_at?->toIso8601String(),
        ]);
    }

    private function authorizedBranchId(int $tenantId, ?int $actorBranchId, ?int $requestedBranchId): ?int
    {
        $branchId = $actorBranchId ?: $requestedBranchId;
        if (!$branchId) {
            return null;
        }

        abort_unless(
            DB::table('branches')->where('id', $branchId)->where('tenant_id', $tenantId)->exists(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );

        return $branchId;
    }
}
