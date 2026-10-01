<?php

namespace Modules\Menu\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Menu\Http\Requests\Api\V1\SaveOnlineMenuRequest;
use Modules\Menu\Services\OnlineMenu\OnlineMenuServiceInterface;
use Modules\Menu\Transformers\Api\V1\OnlineMenuResource;
use Modules\Branch\Support\BranchSchedule;
use Modules\Cart\Support\PublicTenantGuard;
use Modules\Menu\Models\OnlineMenu;
use Modules\Saas\Models\CustomerAppSetting;
use Modules\Support\ApiResponse;
use Illuminate\Support\Facades\DB;
use Modules\Saas\Models\Tenant;

class OnlineMenuController extends Controller
{
    /**
     * Create a new instance of OnlineMenuController
     *
     * @param OnlineMenuServiceInterface $service
     */
    public function __construct(protected OnlineMenuServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of OnlineMenu models.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: OnlineMenuResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters(
                    auth()->user()->assignedToBranch()
                        ? auth()->user()->branch_id
                        : $request->get('filters')['branch_id'] ?? null
                )
                : null
        );
    }

    /**
     * This method retrieves and returns a single OnlineMenu model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new OnlineMenuResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the OnlineMenu model.
     *
     * @param SaveOnlineMenuRequest $request
     * @return JsonResponse
     */
    public function store(SaveOnlineMenuRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new OnlineMenuResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the OnlineMenu model.
     *
     * @param SaveOnlineMenuRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveOnlineMenuRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new OnlineMenuResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the OnlineMenu model based on the provided ids.
     *
     * @param string $ids
     * @return JsonResponse
     */
    public function destroy(string $ids): JsonResponse
    {
        return ApiResponse::destroyed(
            destroyed: $this->service->destroy($ids),
            resource: $this->service->label()
        );
    }

    /**
     * Get form meta
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getFormMeta(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->service->getFormMeta(
                auth()->user()->assignedToBranch()
                    ? auth()->user()->branch_id
                    : $request->get('branch_id')
            )
        );
    }

    /**
     * Get online menu data
     *
     * @param string $slug
     * @return JsonResponse
     */
    public function getMenu(string $slug): JsonResponse
    {
        return ApiResponse::success($this->service->getMenu($slug));
    }

    /**
     * Tenant-safe restaurant discovery for branded customer applications.
     * Each active branch menu is presented as a restaurant location.
     */
    public function customerRestaurants(Request $request): JsonResponse
    {
        $tenantId = PublicTenantGuard::tenantId($request);
        $customerAppSettings = CustomerAppSetting::query()->where('tenant_id', $tenantId)->first();
        $nearbyRadiusKm = (float) data_get($customerAppSettings?->settings ?? [], 'nearby_radius_km', 25);
        $ratingByBranch = DB::table('order_feedback')
            ->whereIn('branch_id', DB::table('branches')->select('id')->where('tenant_id', $tenantId))
            ->groupBy('branch_id')
            ->selectRaw('branch_id, ROUND(AVG(rating), 1) as rating, COUNT(*) as rating_count')
            ->get()->keyBy('branch_id');
        $tenant = Tenant::query()->withoutGlobalScopes()->select('id', 'name', 'settings')->find($tenantId);
        $logoUrl = data_get($tenant?->settings, 'branding.logo_url')
            ?? data_get($tenant?->settings, 'logo_url')
            ?? data_get($tenant?->settings, 'restaurant_logo_url');
        $settings = $customerAppSettings?->settings ?? [];
        $schedule = new BranchSchedule();
        $origin = $this->resolveOrigin($request);

        $restaurants = OnlineMenu::query()
            ->with([
                'menu:id,uuid',
                'branch:id,uuid,tenant_id,name,address_line1,address_line2,city,currency,phone,timezone,latitude,longitude,delivery_radius_km,delivery_minimum_order,cuisines,opening_hours,delivery_eta_minutes,price_for_two,is_accepting_orders,order_types,payment_methods',
                'branch.files',
            ])
            ->withOutGlobalBranchPermission()
            ->where('is_active', true)
            ->whereHas('branch', fn ($query) => $query
                ->where('tenant_id', $tenantId)
                ->where('is_active', true))
            ->orderBy('name')
            ->get()
            ->map(function (OnlineMenu $onlineMenu) use ($settings, $logoUrl, $nearbyRadiusKm, $ratingByBranch, $schedule, $origin) {
                $branch = $onlineMenu->branch;
                $branchId = $onlineMenu->branch_id;

                // Branch columns are authoritative; the legacy customer_app_settings
                // keys stay as a fallback so tenants that only ever filled in the
                // JSON blob do not regress when this ships.
                $cuisines = $branch?->cuisines ?: data_get($settings, 'branch_cuisines.'.$branchId, []);
                $eta = $branch?->delivery_eta_minutes ?? data_get($settings, 'delivery_eta_minutes');
                $availability = $branch
                    ? $schedule->describe($branch)
                    : ['is_open' => true, 'opens_at' => null, 'closes_at' => null];

                return [
                    'slug' => $onlineMenu->slug,
                    'menu_reference' => $onlineMenu->menu?->uuid,
                    'branch_reference' => $branch?->uuid,
                    'name' => $onlineMenu->name ?: $branch?->name,
                    'branch_id' => $branchId,
                    'branch_name' => $branch?->name,
                    'address' => collect([
                        $branch?->address_line1,
                        $branch?->address_line2,
                        $branch?->city,
                    ])->filter()->implode(', '),
                    'city' => $branch?->city,
                    'phone' => $branch?->phone,
                    'logo_url' => $branch?->logo?->preview_image_url ?? $logoUrl,
                    'cover_image_url' => $branch?->cover?->preview_image_url,
                    'currency' => $branch?->currency,
                    'latitude' => $branch?->latitude,
                    'longitude' => $branch?->longitude,
                    'distance_km' => $this->distanceKm($origin, $branch?->latitude, $branch?->longitude),
                    'delivery_radius_km' => $branch?->delivery_radius_km,
                    'delivery_minimum_order' => $branch?->delivery_minimum_order,
                    'nearby_radius_km' => max(1, min(500, $nearbyRadiusKm)),
                    'delivery_eta_minutes' => $eta === null ? null : (int) $eta,
                    'price_for_two' => $branch?->price_for_two,
                    'opening_hours' => $branch?->opening_hours,
                    'is_open' => $availability['is_open'],
                    'opens_at' => $availability['opens_at'],
                    'closes_at' => $availability['closes_at'],
                    'cuisines' => array_values((array) $cuisines),
                    'offer_label' => data_get($settings, 'branch_offers.'.$branchId),
                    'order_types' => $branch && app(\Modules\Order\Delivery\DeliveryAvailability::class)->status($branch)['available']
                        ? ($branch->order_types ?? [])
                        : collect($branch?->order_types ?? [])->reject(fn ($type) =>
                            (is_object($type) && property_exists($type, 'value') ? $type->value : (string) $type)
                                === \Modules\Order\Enums\OrderType::Delivery->value)->values()->all(),
                    'payment_methods' => $branch?->payment_methods ?? [],
                    'rating' => (float) ($ratingByBranch->get($branchId)?->rating ?? 0),
                    'rating_count' => (int) ($ratingByBranch->get($branchId)?->rating_count ?? 0),
                ];
            })
            ->sortBy(fn (array $restaurant) => $restaurant['distance_km'] ?? INF)
            ->values();

        $publicOtpChannels = array_values(array_intersect(
            ['whatsapp', 'email'],
            (array) data_get($customerAppSettings?->settings ?? [], 'otp_channels', config('services.customer_otp.channels', []))
        ));
        if (data_get($customerAppSettings?->settings ?? [], 'email_templates.customer_otp.is_active', true) === false) {
            $publicOtpChannels = array_values(array_diff($publicOtpChannels, ['email']));
        }

        return ApiResponse::success([
            'brand' => ['name' => $tenant?->name, 'logo_url' => $logoUrl],
            'outlet_count' => $restaurants->count(),
            'direct_outlet_slug' => $restaurants->count() === 1 ? $restaurants->first()['slug'] : null,
            'features' => [
                'passwordless_otp' => true,
                'otp_channels' => $publicOtpChannels,
                'google_sign_in' => (bool) data_get($customerAppSettings?->settings ?? [], 'google_sign_in_enabled', false)
                    && filled(data_get($customerAppSettings?->settings ?? [], 'google_server_client_id')),
                // OAuth client IDs identify an application and are safe to
                // publish. Client secrets are never accepted or returned.
                'google_server_client_id' => data_get($customerAppSettings?->settings ?? [], 'google_sign_in_enabled', false)
                    ? data_get($customerAppSettings?->settings ?? [], 'google_server_client_id')
                    : null,
                'outlet_discovery' => $restaurants->count() > 1,
            ],
            'restaurants' => $restaurants,
        ]);
    }

    /**
     * Read an optional caller position from the request. Discovery still works
     * without it — the feed is simply returned unsorted by distance.
     *
     * @return array{0: float, 1: float}|null
     */
    private function resolveOrigin(Request $request): ?array
    {
        $latitude = $request->query('lat');
        $longitude = $request->query('lng');

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        if (abs($latitude) > 90 || abs($longitude) > 180) {
            return null;
        }

        return [$latitude, $longitude];
    }

    /**
     * Great-circle distance in kilometres, rounded to one decimal.
     *
     * @param array{0: float, 1: float}|null $origin
     */
    private function distanceKm(?array $origin, float|string|null $latitude, float|string|null $longitude): ?float
    {
        if ($origin === null || $latitude === null || $longitude === null) {
            return null;
        }

        [$originLat, $originLng] = $origin;
        $earthRadiusKm = 6371;

        $deltaLat = deg2rad((float) $latitude - $originLat);
        $deltaLng = deg2rad((float) $longitude - $originLng);

        $a = sin($deltaLat / 2) ** 2
            + cos(deg2rad($originLat)) * cos(deg2rad((float) $latitude)) * sin($deltaLng / 2) ** 2;

        return round($earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a)), 1);
    }
}
