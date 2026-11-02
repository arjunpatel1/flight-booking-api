<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Models\FlightCoupon;
use Modules\Flight\Models\FlightDiscount;
use Modules\Flight\Models\FlightOffer;
use Modules\Support\ApiResponse;
use Modules\Support\Enums\PriceType;
use Modules\Support\GlobalStructureFilters;

/**
 * Admin CRUD for a tenant's flight coupons (/flight/coupons) and flight
 * discounts (/flight/discounts). The route's `kind` default picks the table.
 * Every query is limited to the signed-in admin's tenant, so one agency never
 * sees or edits another agency's offers.
 *
 * Responses keep the shape the admin voucher screens already use.
 */
class FlightOfferAdminController extends Controller
{
    private const SORTABLE = ['name', 'code', 'type', 'value', 'used', 'is_active', 'created_at', 'updated_at'];

    public function index(Request $request): JsonResponse
    {
        $kind = $this->kind($request);
        $filters = (array) $request->input('filters', []);
        $query = $this->query($request, $kind)
            ->when(filled($filters['search'] ?? null), function (Builder $q) use ($filters): void {
                $term = '%' . trim((string) $filters['search']) . '%';
                $q->where(fn (Builder $inner) => $inner->where('name', 'like', $term)->orWhere('code', 'like', $term));
            })
            ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null && $filters['is_active'] !== '',
                fn (Builder $q) => $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)))
            ->when(in_array($filters['type'] ?? null, [PriceType::Fixed->value, PriceType::Percent->value], true),
                fn (Builder $q) => $q->where('type', $filters['type']))
            ->when(filled($filters['from'] ?? null), fn (Builder $q) => $q->whereDate('created_at', '>=', date('Y-m-d', strtotime((string) $filters['from']))))
            ->when(filled($filters['to'] ?? null), fn (Builder $q) => $q->whereDate('created_at', '<=', date('Y-m-d', strtotime((string) $filters['to']))));

        $sort = collect((array) $request->input('sorts', []))->first();
        $sortKey = in_array($sort['key'] ?? null, self::SORTABLE, true) ? $sort['key'] : 'created_at';
        $query->orderBy($sortKey, ($sort['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc');

        $page = $query->paginate(max(1, min(100, (int) $request->input('per_page', 10))));
        $page->setCollection($page->getCollection()->map(fn (FlightOffer $offer) => $this->listItem($offer)));

        return ApiResponse::pagination(
            paginator: $page,
            filters: $request->get('with_filters') ? $this->structureFilters() : null,
        );
    }

    /** Filters shown above the admin table (same set as the voucher list, minus branch). */
    private function structureFilters(): array
    {
        return [
            [
                'key' => 'type',
                'label' => __('voucher::vouchers.filters.type'),
                'type' => 'select',
                'options' => PriceType::toArrayTrans(),
            ],
            GlobalStructureFilters::active(),
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $kind = $this->kind($request);
        return ApiResponse::success($this->detail($this->query($request, $kind)->findOrFail($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $kind = $this->kind($request);
        $tenantId = $this->tenantId($request);
        $offer = $this->model($kind)->newQuery()->create($this->validated($request, $kind, $tenantId) + [
            'tenant_id' => $tenantId,
            'created_by' => $request->user()?->id,
        ]);

        return ApiResponse::created(body: $this->listItem($offer), resource: $this->label($kind));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $kind = $this->kind($request);
        $offer = $this->query($request, $kind)->findOrFail($id);
        $offer->update($this->validated($request, $kind, $offer->tenant_id, $offer));

        return ApiResponse::updated(body: $this->listItem($offer->fresh()), resource: $this->label($kind));
    }

    public function destroy(Request $request, string $ids): JsonResponse
    {
        $kind = $this->kind($request);
        $idList = array_filter(array_map('intval', explode(',', $ids)));
        $deleted = $this->query($request, $kind)->whereIn('id', $idList)->get()->each->delete()->count();

        return ApiResponse::destroyed(destroyed: $deleted > 0, resource: $this->label($kind));
    }

    /** Options for the form's "Type" select, as the voucher form expects. */
    public function formMeta(): JsonResponse
    {
        return ApiResponse::success([
            'types' => PriceType::toArrayTrans(),
            'branches' => [],
            'categories' => [],
            'days' => [],
            'orderTypes' => [],
            'products' => [],
        ]);
    }

    /** coupon | discount, set on the route with ->defaults('kind', ...). */
    private function kind(Request $request): string
    {
        return $request->route('kind') === 'discount' ? 'discount' : 'coupon';
    }

    private function model(string $kind): FlightOffer
    {
        return $kind === 'discount' ? new FlightDiscount() : new FlightCoupon();
    }

    private function label(string $kind): string
    {
        return $kind === 'discount' ? 'Flight discount' : 'Flight coupon';
    }

    private function tenantId(Request $request): int
    {
        $tenantId = (int) ($request->user()?->tenant_id ?? 0);
        abort_unless($tenantId > 0, 403, 'A tenant account is required to manage flight offers.');

        return $tenantId;
    }

    private function query(Request $request, string $kind): Builder
    {
        return $this->model($kind)->newQuery()->forTenant($this->tenantId($request));
    }

    private function validated(Request $request, string $kind, int $tenantId, ?FlightOffer $offer = null): array
    {
        $table = $this->model($kind)->getTable();
        $data = $request->validate([
            'name' => 'required|array',
            'name.*' => 'nullable|string|max:255',
            'description' => 'nullable|array',
            'description.*' => 'nullable|string|max:1000',
            'code' => [
                $kind === 'coupon' ? 'required' : 'nullable',
                'string',
                'max:200',
                Rule::unique($table, 'code')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->ignore($offer?->id),
            ],
            'type' => ['required', Rule::enum(PriceType::class)],
            'value' => $request->input('type') === PriceType::Percent->value
                ? 'required|numeric|min:0.0001|max:100'
                : 'required|numeric|min:0.0001',
            'is_active' => 'required|boolean',
            'minimum_spend' => 'nullable|numeric|min:0|max:99999999999999',
            'maximum_spend' => 'nullable|numeric|min:0|max:99999999999999|gte:minimum_spend',
            'max_discount' => 'nullable|numeric|min:1|max:99999999999999',
            'usage_limit' => 'nullable|integer|min:1|max:4294967295',
            'per_customer_limit' => 'nullable|integer|min:1|max:4294967295|lte:usage_limit',
            // Only a new offer must start today or later; editing an older one keeps its start.
            'start_date' => $offer ? 'nullable|date_format:Y-m-d' : 'nullable|date_format:Y-m-d|after_or_equal:today',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'conditions' => 'nullable|array',
            'conditions.airlines' => 'nullable|array',
            'conditions.airlines.*' => 'required|string|max:10',
            'conditions.flight_numbers' => 'nullable|array',
            'conditions.flight_numbers.*' => 'required|string|max:32',
            'conditions.routes' => 'nullable|array',
            'conditions.routes.*' => ['required', 'string', 'regex:/^[A-Za-z0-9]{3}-[A-Za-z0-9]{3}$/'],
            'conditions.fare_families' => 'nullable|array',
            'conditions.fare_families.*' => 'required|string|max:64',
        ]);

        $names = array_filter(array_map(fn ($v) => trim((string) $v), (array) $data['name']));
        if ($names === []) {
            throw ValidationException::withMessages(['name' => 'Enter a name for this offer.']);
        }

        return [
            'name' => $names,
            'description' => array_filter(array_map(fn ($v) => trim((string) $v), (array) ($data['description'] ?? []))) ?: null,
            'code' => filled($data['code'] ?? null) ? trim((string) $data['code']) : null,
            'type' => $data['type'],
            'value' => $data['value'],
            'is_active' => (bool) $data['is_active'],
            // A fixed discount has no "maximum discount" cap.
            'max_discount' => $data['type'] === PriceType::Percent->value ? ($data['max_discount'] ?? null) : null,
            'minimum_spend' => $data['minimum_spend'] ?? null,
            'maximum_spend' => $data['maximum_spend'] ?? null,
            'usage_limit' => $data['usage_limit'] ?? null,
            'per_customer_limit' => $data['per_customer_limit'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'conditions' => [
                'airlines' => array_values((array) ($data['conditions']['airlines'] ?? [])),
                'flight_numbers' => array_values((array) ($data['conditions']['flight_numbers'] ?? [])),
                'routes' => array_values(array_map('strtoupper', (array) ($data['conditions']['routes'] ?? []))),
                'fare_families' => array_values((array) ($data['conditions']['fare_families'] ?? [])),
            ],
        ];
    }

    /** One row in the admin list. */
    private function listItem(FlightOffer $offer): array
    {
        return [
            'id' => $offer->id,
            'name' => $offer->localized('name'),
            'code' => $offer->code,
            'type' => $offer->type?->toTrans(),
            'value_text' => $offer->valueText(),
            'branch' => ['id' => null, 'name' => ''],
            'is_active' => $offer->is_active,
            'flight_auto_apply' => $offer->kind() === 'discount',
            'used' => $offer->used,
            'updated_at' => dateTimeFormat($offer->updated_at),
            'created_at' => dateTimeFormat($offer->created_at),
        ];
    }

    /** Everything the edit form needs (names/descriptions in every language). */
    private function detail(FlightOffer $offer): array
    {
        $money = fn ($value) => $value === null ? null : ['amount' => (float) $value];

        return [
            ...$this->listItem($offer),
            'name' => $offer->name ?: [],
            'description' => $offer->description ?: [],
            'value' => (float) $offer->value,
            'minimum_spend' => $money($offer->minimum_spend),
            'maximum_spend' => $money($offer->maximum_spend),
            'max_discount' => $money($offer->max_discount),
            'conditions' => ['channels' => ['flights']] + (array) ($offer->conditions ?? []),
            'usage_limit' => $offer->usage_limit,
            'per_customer_limit' => $offer->per_customer_limit,
            'meta' => ['flight_auto_apply' => $offer->kind() === 'discount'],
            'start_date' => $offer->start_date?->toDateString(),
            'end_date' => $offer->end_date?->toDateString(),
        ];
    }
}
