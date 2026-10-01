<?php

namespace Modules\Cart\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Branch\Models\Branch;
use Modules\Menu\Models\Menu;
use Modules\Product\Models\Product;
use Symfony\Component\HttpFoundation\Response;

class PublicTenantGuard
{
    public static function menu(Request $request, string $reference): Menu
    {
        abort_if(blank($reference), Response::HTTP_UNPROCESSABLE_ENTITY, __('validation.required', [
            'attribute' => 'menu_reference',
        ]));

        return Menu::query()
            ->withoutGlobalScopes()
            ->where('uuid', $reference)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereHas('branch', fn ($query) => $query->withoutGlobalScopes()
                ->where('tenant_id', self::tenantId($request))
                ->where('is_active', true)
                ->whereNull('deleted_at'))
            ->firstOrFail();
    }

    public static function tenantId(Request $request): int
    {
        $tenantId = $request->attributes->get('tenant_id');

        abort_if(blank($tenantId), Response::HTTP_FORBIDDEN, __('auth.failed'));

        return (int) $tenantId;
    }

    public static function branch(Request $request, int|string|null $branchId): Branch
    {
        abort_if(blank($branchId), Response::HTTP_UNPROCESSABLE_ENTITY, __('validation.required', [
            'attribute' => 'branch_id',
        ]));

        return Branch::withoutGlobalActive()
            ->where('tenant_id', self::tenantId($request))
            ->findOrFail((int) $branchId);
    }

    public static function assertBranch(Request $request, int|string|null $branchId): void
    {
        if (blank($branchId)) {
            return;
        }

        self::branch($request, $branchId);
    }

    public static function assertCartProducts(Request $request, int|string $branchId, Collection $items): void
    {
        $branch = self::branch($request, $branchId);
        $productIds = $items
            ->map(fn ($item) => (int) data_get($item, 'product.id'))
            ->filter()
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return;
        }

        $validCount = Product::query()
            ->whereIn('id', $productIds)
            ->where('is_active', true)
            ->whereHas('menu', fn ($query) => $query
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->where('branch_id', $branch->id))
            ->count();

        abort_if(
            $validCount !== $productIds->count(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('cart::validation.the_selected_product_is_invalid')
        );
    }
}
