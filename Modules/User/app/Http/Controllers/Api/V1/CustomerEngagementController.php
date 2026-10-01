<?php

namespace Modules\User\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Branch\Models\Branch;
use Modules\Core\Http\Controllers\Controller;
use Modules\Menu\Models\Menu;
use Modules\Menu\Models\OnlineMenu;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Product\Models\Product;
use Modules\Support\ApiResponse;
use Modules\User\Http\Concerns\ResolvesAppCustomer;
use Symfony\Component\HttpFoundation\Response;

class CustomerEngagementController extends Controller
{
    use ResolvesAppCustomer;

    public function favourites(Request $request): JsonResponse
    {
        $customer = $this->customerForRequest($request);
        $rows = DB::table('customer_favourites')
            ->where('tenant_id', $customer->tenant_id)
            ->where('customer_id', $customer->id)
            ->get(['type', 'subject_id', 'branch_id']);

        $productIds = $rows->where('type', 'product')->pluck('subject_id')->map(fn ($id) => (int) $id)->values();
        $products = Product::query()->whereIn('id', $productIds)->get()->map(fn (Product $product) => [
            'reference' => $product->uuid,
            'id' => $product->id, // Deprecated compatibility field.
            'name' => $product->name,
            'description' => $product->description,
            'thumbnail_url' => $product->thumbnail_url,
            'price' => $product->price,
            'is_available' => (bool) $product->is_active,
        ])->values();
        $restaurantReferences = OnlineMenu::query()->withoutGlobalScopes()
            ->with('menu:id,uuid')
            ->whereIn('branch_id', $rows->where('type', 'restaurant')->pluck('subject_id'))
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereHas('branch', fn ($query) => $query->withoutGlobalScopes()
                ->where('tenant_id', $customer->tenant_id))
            ->get()
            ->pluck('menu.uuid')
            ->filter()
            ->unique()
            ->values();

        return ApiResponse::success([
            'product_references' => $products->pluck('reference')->filter()->values(),
            // Deprecated compatibility field for older clients.
            'product_ids' => $productIds,
            'products' => $products,
            'restaurants' => $restaurantReferences,
        ]);
    }

    public function saveFavourite(Request $request): JsonResponse
    {
        $customer = $this->customerForRequest($request);
        $data = $request->validate([
            'type' => ['required', Rule::in(['product', 'restaurant'])],
            'subject_id' => ['required_without:subject_reference', 'nullable', 'integer', 'min:1'],
            'subject_reference' => ['required_without:subject_id', 'nullable', 'uuid'],
        ]);
        if ($data['type'] === 'restaurant') {
            $branchId = $this->branchIdForMenuReference((int) $customer->tenant_id, (string) $data['subject_reference']);
            $subjectId = $branchId;
        } else {
            [$subjectId, $branchId] = $this->favouriteProductIdentity(
                (int) $customer->tenant_id,
                $data['subject_reference'] ?? null,
                isset($data['subject_id']) ? (int) $data['subject_id'] : null,
            );
        }
        DB::table('customer_favourites')->updateOrInsert([
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->id,
            'type' => $data['type'],
            'subject_id' => $subjectId,
        ], ['branch_id' => $branchId, 'updated_at' => now(), 'created_at' => now()]);

        return ApiResponse::success(['favourite' => true]);
    }

    public function deleteFavourite(Request $request, string $type, string $subject): JsonResponse
    {
        $customer = $this->customerForRequest($request);
        abort_unless(in_array($type, ['product', 'restaurant'], true), Response::HTTP_NOT_FOUND);
        if ($type === 'restaurant') {
            $subjectId = $this->branchIdForMenuReference((int) $customer->tenant_id, $subject);
        } else {
            [$subjectId] = $this->favouriteProductIdentity(
                (int) $customer->tenant_id,
                Str::isUuid($subject) ? $subject : null,
                ctype_digit($subject) ? (int) $subject : null,
            );
        }
        DB::table('customer_favourites')
            ->where('tenant_id', $customer->tenant_id)
            ->where('customer_id', $customer->id)
            ->where('type', $type)
            ->where('subject_id', $subjectId)
            ->delete();

        return ApiResponse::success(['favourite' => false]);
    }

    public function reports(Request $request): JsonResponse
    {
        $customer = $this->customerForRequest($request);
        $reports = DB::table('customer_trust_reports')
            ->where('tenant_id', $customer->tenant_id)
            ->where('customer_id', $customer->id)
            ->latest()
            ->limit(50)
            ->get(['uuid', 'subject_type', 'category', 'status', 'created_at', 'resolved_at']);

        return ApiResponse::success(['reports' => $reports]);
    }

    public function submitReport(Request $request): JsonResponse
    {
        $customer = $this->customerForRequest($request);
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['restaurant', 'product', 'order'])],
            'subject_reference' => ['required_without:subject_id', 'nullable', 'string', 'max:100'],
            // Kept temporarily for older clients. New clients must use opaque references.
            'subject_id' => ['required_without:subject_reference', 'nullable', 'integer', 'min:1'],
            'category' => ['required', Rule::in(['fraud', 'misleading_listing', 'food_safety', 'pricing', 'service', 'other'])],
            'description' => ['required', 'string', 'min:20', 'max:2000'],
            'evidence' => ['nullable', 'array', 'max:3'],
            'evidence.*' => ['string', 'max:500'],
        ]);
        [$branchId, $subjectId] = $this->resolveReportSubject(
            $customer,
            $data['subject_type'],
            $data['subject_reference'] ?? null,
            isset($data['subject_id']) ? (int) $data['subject_id'] : null,
        );
        $duplicate = DB::table('customer_trust_reports')
            ->where('tenant_id', $customer->tenant_id)
            ->where('customer_id', $customer->id)
            ->where('subject_type', $data['subject_type'])
            ->where('subject_id', $subjectId)
            ->whereIn('status', ['submitted', 'triaged', 'investigating', 'restaurant_response'])
            ->exists();
        abort_if($duplicate, Response::HTTP_CONFLICT, 'You already have an active report for this item.');

        $uuid = (string) Str::uuid();
        DB::transaction(function () use ($uuid, $customer, $branchId, $subjectId, $data) {
            DB::table('customer_trust_reports')->insert([
                'uuid' => $uuid, 'tenant_id' => $customer->tenant_id, 'branch_id' => $branchId,
                'customer_id' => $customer->id, 'subject_type' => $data['subject_type'],
                'subject_id' => $subjectId, 'category' => $data['category'],
                'description' => trim($data['description']), 'evidence' => json_encode($data['evidence'] ?? []),
                'status' => 'submitted', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('customer_trust_report_events')->insert([
                'report_uuid' => $uuid, 'tenant_id' => $customer->tenant_id, 'actor_id' => $customer->id,
                'actor_type' => 'customer', 'event' => 'submitted', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return ApiResponse::created(['uuid' => $uuid, 'status' => 'submitted'], message: 'Your report was submitted for review.');
    }

    private function ownedBranchId(int $tenantId, string $type, int $id): int
    {
        if ($type === 'restaurant') {
            return (int) Branch::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($id)->where('is_active', true)->firstOrFail()->id;
        }
        $product = Product::query()->withoutGlobalScopes()->with('branch')->whereKey($id)->firstOrFail();
        abort_unless((int) $product->branch?->tenant_id === $tenantId, Response::HTTP_NOT_FOUND);
        return (int) $product->branch->id;
    }

    /** @return array{0:int,1:int} product id, branch id */
    private function favouriteProductIdentity(int $tenantId, ?string $reference, ?int $legacyId): array
    {
        $product = Product::query()->withoutGlobalScopes()
            ->with('branch')
            ->when(filled($reference), fn ($query) => $query->where('uuid', $reference))
            ->when(blank($reference), fn ($query) => $query->whereKey($legacyId))
            ->where('is_active', true)
            ->firstOrFail();
        abort_unless((int) $product->branch?->tenant_id === $tenantId, Response::HTTP_NOT_FOUND);

        return [(int) $product->id, (int) $product->branch->id];
    }

    private function branchIdForMenuReference(int $tenantId, string $reference): int
    {
        $menu = Menu::query()->withoutGlobalScopes()
            ->where('uuid', $reference)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereHas('branch', fn ($query) => $query->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true))
            ->firstOrFail();

        return (int) $menu->branch_id;
    }

    private function reportBranchId($customer, string $type, int $id): int
    {
        if ($type !== 'order') return $this->ownedBranchId((int) $customer->tenant_id, $type, $id);
        $order = Order::query()->withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->whereKey($id)
            ->where('status', OrderStatus::Completed->value)
            ->whereHas('branch', fn ($query) => $query->withoutGlobalScopes()->where('tenant_id', $customer->tenant_id))
            ->firstOrFail();
        return (int) $order->branch_id;
    }

    /**
     * Resolve public opaque references only after authenticating the customer and
     * constraining the lookup to their tenant/account. Numeric IDs never leave
     * this controller's internal persistence boundary for new clients.
     *
     * @return array{0:int,1:int}
     */
    private function resolveReportSubject($customer, string $type, ?string $reference, ?int $legacyId): array
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            abort_if($legacyId === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'A subject reference is required.');
            $branchId = $this->reportBranchId($customer, $type, $legacyId);
            return [$branchId, $legacyId];
        }

        if ($type === 'restaurant') {
            $branchId = $this->branchIdForMenuReference((int) $customer->tenant_id, $reference);
            return [$branchId, $branchId];
        }

        if ($type === 'order') {
            $order = Order::query()->withoutGlobalScopes()
                ->where('reference_no', $reference)
                ->where('customer_id', $customer->id)
                ->where('status', OrderStatus::Completed->value)
                ->whereHas('branch', fn ($query) => $query->withoutGlobalScopes()
                    ->where('tenant_id', $customer->tenant_id))
                ->firstOrFail();
            return [(int) $order->branch_id, (int) $order->id];
        }

        abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Product reports require a supported product reference.');
    }
}
