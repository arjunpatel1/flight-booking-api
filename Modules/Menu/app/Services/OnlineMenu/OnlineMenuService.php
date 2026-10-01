<?php

namespace Modules\Menu\Services\OnlineMenu;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Branch\Models\Branch;
use Modules\Branch\Support\BranchSchedule;
use Modules\Menu\Models\Menu;
use Modules\Menu\Models\OnlineMenu;
use Modules\Media\Models\Media;
use Modules\Pos\Services\PosViewer\PosViewerServiceInterface;
use Modules\Saas\Models\CustomerAppSetting;
use Modules\Saas\Services\CustomerApp\CustomerAppContentService;
use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;
use Modules\SeatingPlan\Models\Table;
use Modules\Support\GlobalStructureFilters;

class OnlineMenuService implements OnlineMenuServiceInterface
{
    /** {@inheritDoc} */
    public function label(): string
    {
        return __('menu::online_menus.online_menu');
    }

    /** {@inheritDoc} */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with(['branch:id,name', 'hotelBranch:id,name', 'menu:id,name'])
            ->withoutGlobalActive()
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** {@inheritDoc} */
    public function getModel(): OnlineMenu
    {
        return new ($this->model());
    }

    /** {@inheritDoc} */
    public function model(): string
    {
        return OnlineMenu::class;
    }

    /** {@inheritDoc} */
    public function show(int $id): OnlineMenu
    {
        return $this->findOrFail($id);
    }

    /** {@inheritDoc} */
    public function findOrFail(int $id): Builder|array|EloquentCollection|OnlineMenu
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->findOrFail($id);
    }

    /** {@inheritDoc} */
    public function store(array $data): OnlineMenu
    {
        return $this->getModel()->query()->create($data);
    }

    /** {@inheritDoc} */
    public function update(int $id, array $data): OnlineMenu
    {
        $onlineMenu = $this->findOrFail($id);
        $onlineMenu->update($data);

        return $onlineMenu;
    }

    /** {@inheritDoc} */
    public function destroy(int|array|string $ids): bool
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->whereIn('id', parseIds($ids))
            ->delete() ?: false;
    }

    /** {@inheritDoc} */
    public function getStructureFilters(?int $branchId): array
    {
        $branchFilter = GlobalStructureFilters::branch();

        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                'key' => 'menu_id',
                'label' => __('menu::online_menus.filters.menu'),
                'type' => 'select',
                'options' => ! is_null($branchId) ? Menu::list($branchId, true) : [],
                'depends' => 'branch_id',
            ],
            GlobalStructureFilters::active(),
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** {@inheritDoc} */
    public function getFormMeta(?int $branchId): array
    {
        if (is_null($branchId)) {
            return [
                'branches' => Branch::list(),
            ];
        } else {
            return [
                'menus' => Menu::list($branchId, true),
            ];
        }
    }

    /** {@inheritDoc} */
    public function getMenu(string $slug): array
    {
        $onlineMenu = $this->getModel()->findBySlug($slug, [
            'branch:id,uuid,tenant_id,name,phone,email,address_line1,address_line2,city,currency,timezone,latitude,longitude,delivery_radius_km,delivery_minimum_order,cuisines,opening_hours,delivery_eta_minutes,price_for_two,is_accepting_orders,order_types,payment_methods',
            'branch.files',
            'menu:id,uuid,name',
        ]);

        abort_if(is_null($onlineMenu), 404);

        $menu = $onlineMenu->menu ?: Menu::getActiveMenu($onlineMenu->branch_id);
        $tenant = \Modules\Saas\Models\Tenant::query()->withoutGlobalScopes()
            // CustomerAppContentService::runtimePayload also reads the brand
            // name. Keep the projection explicit, but include every tenant
            // attribute consumed by that public runtime payload.
            ->select('id', 'name', 'settings')->find($onlineMenu->branch?->tenant_id);
        $logoUrl = data_get($tenant?->settings, 'branding.logo_url')
            ?? data_get($tenant?->settings, 'logo_url')
            ?? data_get($tenant?->settings, 'restaurant_logo_url')
            // The tenant settings screen stores uploaded branding as a media
            // id in the normal settings store. Tenant JSON branding is used
            // by the white-label app builder, so it cannot be the only source
            // for the public ordering header.
            ?? Media::getCacheMedia(setting('logo'))?->url;
        $customerContent = $tenant
            ? app(CustomerAppContentService::class)->runtimePayload($tenant)['content']
            : ['sliders' => [], 'banners' => [], 'offers' => [], 'events' => []];
        $customerAppSettings = CustomerAppSetting::query()->withoutGlobalScopes()
            ->where('tenant_id', $onlineMenu->branch?->tenant_id)
            ->first()?->settings ?? [];
        // This public capability is used to decide whether customer sign-in
        // and account routes may be shown. It must reflect both the tenant
        // setting and the active subscription, otherwise a disabled plan can
        // display a working-looking login and fail only after authentication.
        $customerAppEnabled = $tenant
            && app(EffectiveTenantEntitlementService::class)->has($tenant, 'customer_app')
            && (bool) setting('customer_app_enabled', true);
        $googleSignInEnabled = (bool) data_get($customerAppSettings, 'google_sign_in_enabled', false)
            && filled(data_get($customerAppSettings, 'google_server_client_id'));
        $appleSignInEnabled = (bool) data_get($customerAppSettings, 'apple_sign_in_enabled', false)
            && filled(data_get($customerAppSettings, 'apple_service_id'));
        $otpChannels = array_values(array_intersect(
            ['whatsapp', 'email'],
            (array) data_get($customerAppSettings, 'otp_channels', config('services.customer_otp.channels', ['whatsapp', 'email']))
        ));
        if (data_get($customerAppSettings, 'email_templates.customer_otp.is_active', true) === false) {
            $otpChannels = array_values(array_diff($otpChannels, ['email']));
        }

        $availability = $onlineMenu->branch
            ? (new BranchSchedule)->describe($onlineMenu->branch)
            : ['is_open' => true, 'opens_at' => null, 'closes_at' => null];
        $branchOffersDelivery = collect($onlineMenu->branch?->order_types ?? [])
            ->map(fn ($type) => is_object($type) && property_exists($type, 'value') ? $type->value : (string) $type)
            ->contains(\Modules\Order\Enums\OrderType::Delivery->value);
        $deliveryAvailable = $onlineMenu->branch
            && $branchOffersDelivery
            && (bool) setting('delivery_enabled', false)
            && (bool) setting('customer_app_delivery_enabled', true)
            && app(\Modules\Order\Delivery\DeliveryAvailability::class)->status($onlineMenu->branch)['available'];

        $service = app(PosViewerServiceInterface::class);
        $tableToken = request()->string('table_token')->trim()->toString();
        $tableId = null;
        $table = null;
        if ($tableToken !== '') {
            $table = Table::query()
                ->select(['id', 'uuid', 'name', 'branch_id'])
                ->where('uuid', $tableToken)
                ->where('branch_id', $onlineMenu->branch_id)
                ->where('is_active', true)
                ->first();
            $tableId = $table?->id;
            $tableToken = $table?->uuid ?? '';
        }

        return [
            'restaurant' => [
                'id' => $menu->id,
                'menu_id' => $menu->id,
                'menu_reference' => $menu->uuid,
                'branch_reference' => $onlineMenu->branch?->uuid,
                'branch_id' => $onlineMenu->branch_id,
                'table_id' => $tableId,
                'table_token' => $tableToken !== '' ? $tableToken : null,
                'table_name' => $table?->name,
                'table' => $table ? [
                    'id' => $table->id,
                    'name' => $table->name,
                ] : null,
                'name' => $onlineMenu->name ?: $onlineMenu->branch?->name,
                'branch_name' => $onlineMenu->branch?->name,
                'phone' => $onlineMenu->branch?->phone,
                'email' => $onlineMenu->branch?->email,
                'logo_url' => $onlineMenu->branch?->logo?->preview_image_url ?? $logoUrl,
                'cover_image_url' => $onlineMenu->branch?->cover?->preview_image_url,
                'address' => collect([
                    $onlineMenu->branch?->address_line1,
                    $onlineMenu->branch?->address_line2,
                    $onlineMenu->branch?->city,
                ])->filter()->implode(', '),
                'latitude' => $onlineMenu->branch?->latitude,
                'longitude' => $onlineMenu->branch?->longitude,
                'delivery_radius_km' => $onlineMenu->branch?->delivery_radius_km,
                'delivery_minimum_order' => $onlineMenu->branch?->delivery_minimum_order,
                'cuisines' => array_values((array) ($onlineMenu->branch?->cuisines ?? [])),
                'delivery_eta_minutes' => $onlineMenu->branch?->delivery_eta_minutes,
                'price_for_two' => $onlineMenu->branch?->price_for_two,
                'opening_hours' => $onlineMenu->branch?->opening_hours,
                'is_open' => $availability['is_open'],
                'opens_at' => $availability['opens_at'],
                'closes_at' => $availability['closes_at'],
                'order_types' => $deliveryAvailable
                    ? ($onlineMenu->branch->order_types ?? [])
                    : collect($onlineMenu->branch?->order_types ?? [])->reject(fn ($type) =>
                        (is_object($type) && property_exists($type, 'value') ? $type->value : (string) $type)
                            === \Modules\Order\Enums\OrderType::Delivery->value)->values()->all(),
                'payment_methods' => $onlineMenu->branch?->payment_methods ?? [],
                'delivery_enabled' => $deliveryAvailable,
                'customer_app' => [
                    'enabled' => $customerAppEnabled,
                    'guest_checkout_enabled' => (bool) setting('customer_app_guest_checkout_enabled', true),
                    'reservations_enabled' => (bool) setting('customer_app_reservations_enabled', true),
                    'delivery_enabled' => $deliveryAvailable,
                    'pickup_enabled' => (bool) setting('customer_app_pickup_enabled', true),
                    'table_qr_enabled' => (bool) setting('customer_app_table_qr_enabled', true),
                    'order_tracking_enabled' => (bool) setting('customer_app_order_tracking_enabled', true),
                    'otp_channels' => $otpChannels,
                    'google_sign_in' => $googleSignInEnabled,
                    'apple_sign_in' => $appleSignInEnabled,
                    // OAuth client ids are public identifiers. No OAuth client
                    // secret is ever returned by the public menu payload.
                    'google_server_client_id' => $googleSignInEnabled
                        ? data_get($customerAppSettings, 'google_server_client_id')
                        : null,
                    'apple_service_id' => $appleSignInEnabled
                        ? data_get($customerAppSettings, 'apple_service_id')
                        : null,
                ],
                'currency' => $onlineMenu->branch?->currency,
                'slug' => $onlineMenu->slug,
            ],
            // The public web menu consumes exactly the same published content
            // records that tenant admins manage in Customer App settings.
            'customer_content' => $customerContent,
            'categories' => $service->getCategories($menu->id),
            'products' => $service->getProducts($menu->id, includeOptions: true),
        ];
    }
}
