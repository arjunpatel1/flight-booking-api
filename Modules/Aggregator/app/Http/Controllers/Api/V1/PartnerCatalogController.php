<?php

namespace Modules\Aggregator\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Aggregator\Services\PartnerApi\PartnerResourceService;
use Modules\Aggregator\Support\PartnerApiResponse;
use Modules\Aggregator\Support\PartnerContext;
use Modules\Branch\Models\Branch;
use Modules\Menu\Models\Menu;
use Modules\Product\Models\Product;
use Throwable;

class PartnerCatalogController
{
    public function __construct(private readonly PartnerResourceService $resources)
    {
    }

    public function health(Request $request): JsonResponse
    {
        $context = $this->context($request);

        return PartnerApiResponse::success([
            'status' => 'ok',
            'partner_id' => $context->partner->uuid,
            'environment' => $context->partner->environment,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function branches(Request $request): JsonResponse
    {
        $context = $this->context($request);
        $allowed = array_map('intval', $context->credential->branch_ids ?? []);
        $branches = Branch::query()->withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId())
            ->whereNull('deleted_at')
            ->when($allowed, fn ($query) => $query->whereIn('id', $allowed))
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(fn (Branch $branch) => [
                'id' => $this->resources->externalId($context, 'branch', $branch),
                'name' => $branch->name,
                'timezone' => $branch->timezone,
                'currency' => $branch->currency,
                'accepting_orders' => (bool) $branch->is_accepting_orders,
                'order_types' => $branch->order_types ?? [],
            ]);

        return PartnerApiResponse::success($branches);
    }

    public function menus(Request $request, string $branchUuid): JsonResponse
    {
        $context = $this->context($request);
        $branchId = $this->resources->internalId($context, 'branch', $branchUuid);
        if (! $branchId || ! $context->canAccessBranch($branchId) || ! $this->branchExists($context, $branchId)) {
            return PartnerApiResponse::error('RESOURCE_NOT_FOUND', 'Branch was not found.', 404);
        }
        $request->attributes->set('partner_branch_id', $branchId);

        $menus = Menu::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(fn (Menu $menu) => [
                'id' => $this->resources->externalId($context, 'menu', $menu),
                'name' => $menu->name,
                'description' => $menu->description,
                'order_types' => $menu->order_types ?? [],
            ]);

        return PartnerApiResponse::success($menus);
    }

    public function products(Request $request, string $menuUuid): JsonResponse
    {
        $context = $this->context($request);
        $menuId = $this->resources->internalId($context, 'menu', $menuUuid);
        $menu = $menuId ? Menu::query()->withoutGlobalScopes()->whereKey($menuId)->whereNull('deleted_at')->first() : null;
        if (! $menu || ! $context->canAccessBranch((int) $menu->branch_id) || ! $this->branchExists($context, (int) $menu->branch_id)) {
            return PartnerApiResponse::error('RESOURCE_NOT_FOUND', 'Menu was not found.', 404);
        }
        $request->attributes->set('partner_branch_id', (int) $menu->branch_id);

        $validator = Validator::make($request->query(), [
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if ($validator->fails()) {
            return PartnerApiResponse::error(
                'VALIDATION_ERROR',
                'Pagination parameters are invalid. page must be a positive integer and per_page must be between 1 and 100.',
                422,
                $validator->errors()->toArray(),
            );
        }
        $pagination = $validator->validated();
        $page = (int) ($pagination['page'] ?? 1);
        $perPage = (int) ($pagination['per_page'] ?? 50);

        $products = Product::query()->withoutGlobalScopes()
            ->where('menu_id', $menu->id)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->with(['categories:id,name', 'options.values', 'files'])
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $data = $products->getCollection()
            ->map(fn (Product $product) => $this->serializeProduct($context, $product))
            ->values();

        return PartnerApiResponse::success($data, 200, [
            'page' => $products->currentPage(),
            'per_page' => $products->perPage(),
            'total' => $products->total(),
            'last_page' => $products->lastPage(),
        ]);
    }


    private function serializeProduct(PartnerContext $context, Product $product): array
    {
        return [
            'id' => $this->resources->externalId($context, 'product', $product),
            'sku' => $product->sku,
            'name' => $product->name,
            'description' => $product->description,
            'available' => (bool) $product->is_available,
            'price' => [
                'amount' => number_format($this->productPriceAmount($product), 2, '.', ''),
                'currency' => $this->productCurrency($product),
            ],
            'categories' => $product->categories->pluck('name')->values(),
            'image_url' => $this->productImageUrl($product),
        ];
    }

    private function productPriceAmount(Product $product): float
    {
        try {
            $sellingPrice = $product->selling_price;
            if ($sellingPrice && method_exists($sellingPrice, 'amount')) {
                return (float) $sellingPrice->amount();
            }
        } catch (Throwable) {
            // Fall back to the raw persisted price below. One malformed catalog
            // product must not make a paginated partner catalog page return 500.
        }

        return max(0.0, (float) ($product->getRawOriginal('price') ?? 0));
    }

    private function productCurrency(Product $product): string
    {
        try {
            $currency = trim((string) $product->currency);
            if ($currency !== '') {
                return strtoupper($currency);
            }
        } catch (Throwable) {
            // Fall through to the configured default currency.
        }

        return strtoupper((string) (setting('default_currency') ?: 'INR'));
    }

    private function productImageUrl(Product $product): ?string
    {
        try {
            $url = $product->medium_url ?: $product->thumbnail_url;
            if ($url) {
                return $url;
            }
        } catch (Throwable) {
            // Legacy rows without an image column can still have an attached media file.
        }

        return $product->thumbnail?->preview_image_url;
    }

    private function branchExists(PartnerContext $context, int $branchId): bool
    {
        return Branch::query()->withoutGlobalScopes()
            ->whereKey($branchId)
            ->where('tenant_id', $context->tenantId())
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->exists();
    }

    private function context(Request $request): PartnerContext
    {
        return $request->attributes->get('partner_context');
    }
}
