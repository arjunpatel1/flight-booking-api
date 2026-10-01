<?php

namespace Modules\Saas\Services\CustomerApp;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Saas\Models\CustomerAppBuild;
use Modules\Saas\Models\CustomerAppContentItem;
use Modules\Saas\Models\CustomerAppRegistration;
use Modules\Saas\Models\CustomerAppSetting;
use Modules\Saas\Models\Tenant;
use Modules\User\Models\User;

class CustomerAppContentService
{
    private const MEDIA_DISK = 'public';
    private const MEDIA_ROOT = 'customer-app';
    private const ALLOWED_MEDIA_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const MAX_METADATA_KEYS = 50;
    private const MAX_SETTINGS_KEYS = 50;
    private const MAX_JSON_BYTES = 8192;

    public function __construct(private readonly CustomerAppBuildService $builds) {}

    public function overview(Tenant $tenant): array
    {
        $settings = $this->settings($tenant);
        $latestBuild = CustomerAppBuild::query()
            ->with('artifact')
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->first();
        $registration = CustomerAppRegistration::query()
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->first();

        return [
            'status' => [
                'app_enabled' => (bool) $settings->app_enabled,
                'content_published' => CustomerAppContentItem::query()->forTenant($tenant)->runtimeVisible()->exists(),
                'last_publish_at' => $settings->published_at?->toIso8601String(),
                'last_content_update_at' => $settings->content_updated_at?->toIso8601String(),
                'needs_rebuild' => false,
                'rebuild_reason' => 'Runtime content changes publish instantly. Rebuild only when package, native config, or signing changes.',
            ],
            'settings' => $this->presentSettings($settings),
            'content_summary' => $this->contentSummary($tenant),
            'latest_build' => $latestBuild ? $this->builds->present($latestBuild) : null,
            'registration' => $registration ? $this->presentRegistration($registration) : null,
            'guide' => $this->guide($tenant, $settings, $latestBuild, $registration),
            'locations' => $this->deliveryLocations($tenant),
        ];
    }

    public function settings(Tenant $tenant): CustomerAppSetting
    {
        return CustomerAppSetting::query()->firstOrCreate(
            ['tenant_id' => $tenant->id],
            CustomerAppSetting::defaults(),
        );
    }

    public function updateSettings(Tenant $tenant, array $input, ?User $actor = null): CustomerAppSetting
    {
        $settings = $this->settings($tenant);
        $locations = $input['locations'] ?? null;
        unset($input['locations']);

        if (! blank($input['map_url'] ?? null)) {
            $this->assertSafeExternalUrl((string) $input['map_url']);
        }

        if (isset($input['social_links'])) {
            $this->assertArrayBudget($input['social_links'], 'Social links', self::MAX_SETTINGS_KEYS, self::MAX_JSON_BYTES);
        }

        if (isset($input['settings'])) {
            $this->assertArrayBudget($input['settings'], 'Customer app settings', self::MAX_SETTINGS_KEYS, self::MAX_JSON_BYTES);
        }

        DB::transaction(function () use ($tenant, $settings, $input, $locations): void {
            $settings->fill($input);
            $settings->content_updated_at = now();
            $settings->save();

            if (is_array($locations)) {
                foreach ($locations as $location) {
                    $branch = $tenant->branches()->whereKey((int) $location['branch_id'])->firstOrFail();
                    $branch->update([
                        'latitude' => $location['latitude'] ?? null,
                        'longitude' => $location['longitude'] ?? null,
                        'delivery_radius_km' => $location['delivery_radius_km'] ?? null,
                        'delivery_minimum_order' => $location['delivery_minimum_order'] ?? null,
                    ]);
                }
            }
        });

        $this->audit('customer_app.settings.updated', $tenant, $actor, ['settings_id' => $settings->id]);

        return $settings->refresh();
    }

    private function deliveryLocations(Tenant $tenant): array
    {
        return $tenant->branches()
            ->withoutGlobalActive()
            ->orderBy('name')
            ->get(['id', 'name', 'city', 'address_line1', 'latitude', 'longitude', 'delivery_radius_km', 'delivery_minimum_order', 'is_active'])
            ->map(fn ($branch) => [
                'branch_id' => $branch->id,
                'name' => $branch->name,
                'address' => collect([$branch->address_line1, $branch->city])->filter()->implode(', '),
                'latitude' => $branch->latitude,
                'longitude' => $branch->longitude,
                'delivery_radius_km' => $branch->delivery_radius_km,
                'delivery_minimum_order' => $branch->delivery_minimum_order,
                'is_active' => (bool) $branch->is_active,
            ])->values()->all();
    }

    public function paginate(Tenant $tenant, array $filters = []): LengthAwarePaginator
    {
        $query = CustomerAppContentItem::query()->forTenant($tenant)->ordered();

        if (! blank($filters['type'] ?? null)) {
            $query->where('type', $filters['type']);
        }

        if (! blank($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (array_key_exists('active', $filters) && $filters['active'] !== null && $filters['active'] !== '') {
            $query->where('is_active', filter_var($filters['active'], FILTER_VALIDATE_BOOL));
        }

        if (! blank($filters['search'] ?? null)) {
            $search = trim((string) $filters['search']);
            $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('subtitle', 'like', "%{$search}%")
                    ->orWhere('cta_label', 'like', "%{$search}%");
            });
        }

        return $query->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 25))));
    }

    public function create(Tenant $tenant, array $input, ?User $actor = null): CustomerAppContentItem
    {
        $this->validateContentInput($tenant, $input);

        return DB::transaction(function () use ($tenant, $input, $actor) {
            $item = new CustomerAppContentItem($input);
            $item->tenant_id = $tenant->id;
            $item->created_by = $actor?->getKey();
            $item->updated_by = $actor?->getKey();
            $this->applyPublicationState($item);
            $item->save();

            $this->touchContent($tenant);
            $this->audit('customer_app.content.created', $tenant, $actor, ['content_id' => $item->id, 'type' => $item->type]);

            return $item->refresh();
        });
    }

    public function update(Tenant $tenant, CustomerAppContentItem $item, array $input, ?User $actor = null): CustomerAppContentItem
    {
        $this->assertOwns($tenant, $item);
        $this->validateContentInput($tenant, $input);

        return DB::transaction(function () use ($tenant, $item, $input, $actor) {
            $item->fill($input);
            $item->updated_by = $actor?->getKey();
            $this->applyPublicationState($item);
            $item->save();

            $this->touchContent($tenant);
            $this->audit('customer_app.content.updated', $tenant, $actor, ['content_id' => $item->id, 'type' => $item->type]);

            return $item->refresh();
        });
    }

    public function publish(Tenant $tenant, CustomerAppContentItem $item, ?User $actor = null): CustomerAppContentItem
    {
        $this->assertOwns($tenant, $item);

        $item->forceFill([
            'status' => CustomerAppContentItem::STATUS_PUBLISHED,
            'is_active' => true,
            'published_at' => now(),
            'updated_by' => $actor?->getKey(),
        ])->save();

        $this->settings($tenant)->forceFill(['published_at' => now(), 'content_updated_at' => now()])->save();
        $this->audit('customer_app.content.published', $tenant, $actor, ['content_id' => $item->id]);

        return $item->refresh();
    }

    public function unpublish(Tenant $tenant, CustomerAppContentItem $item, ?User $actor = null): CustomerAppContentItem
    {
        $this->assertOwns($tenant, $item);

        $item->forceFill([
            'status' => CustomerAppContentItem::STATUS_UNPUBLISHED,
            'is_active' => false,
            'updated_by' => $actor?->getKey(),
        ])->save();

        $this->touchContent($tenant);
        $this->audit('customer_app.content.unpublished', $tenant, $actor, ['content_id' => $item->id]);

        return $item->refresh();
    }

    public function delete(Tenant $tenant, CustomerAppContentItem $item, ?User $actor = null): void
    {
        $this->assertOwns($tenant, $item);
        $id = $item->id;
        $item->delete();
        $this->touchContent($tenant);
        $this->audit('customer_app.content.deleted', $tenant, $actor, ['content_id' => $id]);
    }

    public function publishAll(Tenant $tenant, ?User $actor = null): array
    {
        $count = CustomerAppContentItem::query()
            ->forTenant($tenant)
            ->whereIn('status', [CustomerAppContentItem::STATUS_DRAFT, CustomerAppContentItem::STATUS_UNPUBLISHED])
            ->update([
                'status' => CustomerAppContentItem::STATUS_PUBLISHED,
                'is_active' => true,
                'published_at' => now(),
                'updated_by' => $actor?->getKey(),
                'updated_at' => now(),
            ]);

        $this->settings($tenant)->forceFill(['published_at' => now(), 'content_updated_at' => now()])->save();
        $this->audit('customer_app.content.publish_all', $tenant, $actor, ['count' => $count]);

        return ['published_count' => $count, 'runtime' => $this->runtimePayload($tenant)];
    }

    public function uploadMedia(Tenant $tenant, UploadedFile $file, ?User $actor = null): array
    {
        $extension = strtolower($file->guessExtension() ?: $file->extension() ?: 'bin');
        if (! in_array($extension, self::ALLOWED_MEDIA_EXTENSIONS, true)) {
            throw new InvalidArgumentException('Only JPG, PNG, WebP, or GIF images are allowed.');
        }

        $name = Str::uuid()->toString().'.'.$extension;
        $path = $file->storeAs(self::MEDIA_ROOT."/{$tenant->id}", $name, self::MEDIA_DISK);
        $disk = Storage::disk(self::MEDIA_DISK);

        $this->touchContent($tenant);
        $this->audit('customer_app.media.uploaded', $tenant, $actor, ['path' => $path]);

        return [
            'path' => $path,
            'url' => $disk->url($path),
            'size_bytes' => $disk->size($path),
            'checksum' => hash_file('sha256', $disk->path($path)),
        ];
    }

    public function runtimePayload(Tenant $tenant): array
    {
        $settings = $this->settings($tenant);
        $tenantSettings = (array) $tenant->settings;
        $items = CustomerAppContentItem::query()
            ->forTenant($tenant)
            ->runtimeVisible()
            ->ordered()
            ->get()
            ->map(fn (CustomerAppContentItem $item) => $this->presentContent($item, true))
            ->groupBy('type');

        return [
            'enabled' => (bool) $settings->app_enabled,
            'content_revision' => optional($settings->content_updated_at ?? $settings->updated_at)->timestamp,
            'published_at' => $settings->published_at?->toIso8601String(),
            'brand' => [
                'name' => $tenant->name,
                'logo_url' => data_get($tenantSettings, 'branding.logo_url') ?? data_get($tenantSettings, 'logo_url') ?? data_get($tenantSettings, 'restaurant_logo_url'),
                'primary_color' => data_get($tenantSettings, 'primary_color') ?? data_get($tenantSettings, 'theme_primary_color'),
                'secondary_color' => data_get($tenantSettings, 'secondary_color') ?? data_get($tenantSettings, 'theme_secondary_color'),
            ],
            'capabilities' => $this->capabilities($settings),
            'contact' => [
                'phone' => $settings->contact_phone,
                'whatsapp' => $settings->whatsapp_number,
                'address' => $settings->address_line,
                'map_url' => $settings->map_url,
                'social_links' => $settings->social_links ?? [],
            ],
            'content' => [
                'sliders' => array_values(($items->get(CustomerAppContentItem::TYPE_SLIDER) ?? collect())->all()),
                'banners' => array_values(($items->get(CustomerAppContentItem::TYPE_BANNER) ?? collect())->all()),
                'offers' => array_values(($items->get(CustomerAppContentItem::TYPE_OFFER) ?? collect())->all()),
                'events' => array_values(($items->get(CustomerAppContentItem::TYPE_EVENT) ?? collect())->all()),
            ],
        ];
    }

    public function preview(Tenant $tenant): array
    {
        return [
            'label' => 'Content Preview',
            'generated_at' => now()->toIso8601String(),
            'runtime' => $this->runtimePayload($tenant),
            'drafts' => CustomerAppContentItem::query()
                ->forTenant($tenant)
                ->where('status', CustomerAppContentItem::STATUS_DRAFT)
                ->ordered()
                ->limit(20)
                ->get()
                ->map(fn (CustomerAppContentItem $item) => $this->presentContent($item))
                ->values(),
        ];
    }

    public function presentPage(LengthAwarePaginator $page): array
    {
        return [
            'data' => collect($page->items())->map(fn (CustomerAppContentItem $item) => $this->presentContent($item))->values(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    public function presentContent(CustomerAppContentItem $item, bool $runtime = false): array
    {
        $payload = [
            'id' => $item->id,
            'type' => $item->type,
            'title' => $item->title,
            'subtitle' => $item->subtitle,
            'body' => $item->body,
            'image_path' => $item->image_path,
            'image_url' => $this->publicUrl($item->image_path),
            'mobile_image_path' => $item->mobile_image_path,
            'mobile_image_url' => $this->publicUrl($item->mobile_image_path),
            'cta_action' => $item->cta_action,
            'cta_label' => $item->cta_label,
            'cta_target' => $item->cta_target,
            'linked_resource_type' => $item->linked_resource_type,
            'linked_resource_id' => $item->linked_resource_id,
            'status' => $item->status,
            'is_active' => (bool) $item->is_active,
            'display_order' => (int) $item->display_order,
            'starts_at' => $item->starts_at?->toIso8601String(),
            'ends_at' => $item->ends_at?->toIso8601String(),
            'published_at' => $item->published_at?->toIso8601String(),
            'metadata' => $item->metadata ?? [],
        ];

        if (! $runtime) {
            $payload['created_at'] = $item->created_at?->toIso8601String();
            $payload['updated_at'] = $item->updated_at?->toIso8601String();
        }

        return $payload;
    }

    public function presentSettings(CustomerAppSetting $settings): array
    {
        return [
            'app_enabled' => (bool) $settings->app_enabled,
            'sliders_enabled' => (bool) $settings->sliders_enabled,
            'offers_enabled' => (bool) $settings->offers_enabled,
            'events_enabled' => (bool) $settings->events_enabled,
            'customer_registration_enabled' => (bool) $settings->customer_registration_enabled,
            'guest_checkout_enabled' => (bool) $settings->guest_checkout_enabled,
            'delivery_enabled' => (bool) $settings->delivery_enabled,
            'pickup_enabled' => (bool) $settings->pickup_enabled,
            'dine_in_enabled' => (bool) $settings->dine_in_enabled,
            'table_qr_enabled' => (bool) $settings->table_qr_enabled,
            'order_tracking_enabled' => (bool) $settings->order_tracking_enabled,
            'notifications_enabled' => (bool) $settings->notifications_enabled,
            'contact_phone' => $settings->contact_phone,
            'whatsapp_number' => $settings->whatsapp_number,
            'address_line' => $settings->address_line,
            'map_url' => $settings->map_url,
            'social_links' => $settings->social_links ?? [],
            'settings' => $settings->settings ?? [],
            'published_at' => $settings->published_at?->toIso8601String(),
            'content_updated_at' => $settings->content_updated_at?->toIso8601String(),
        ];
    }

    private function validateContentInput(Tenant $tenant, array $input): void
    {
        if (! blank($input['cta_action'] ?? null)) {
            $this->validateCta((string) $input['cta_action'], $input['cta_target'] ?? null);
        }

        if (isset($input['metadata'])) {
            $this->assertArrayBudget($input['metadata'], 'Content metadata', self::MAX_METADATA_KEYS, self::MAX_JSON_BYTES);
        }

        foreach (['image_path', 'mobile_image_path'] as $key) {
            if (! blank($input[$key] ?? null)) {
                $this->assertTenantMediaPath($tenant, (string) $input[$key]);
            }
        }
    }

    private function assertTenantMediaPath(Tenant $tenant, string $path): void
    {
        $path = trim(str_replace('\\', '/', $path));
        $tenantPrefix = self::MEDIA_ROOT.'/'.$tenant->id.'/';

        if ($path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '../')
            || str_contains($path, '/..')
            || ! str_starts_with($path, $tenantPrefix)) {
            throw new InvalidArgumentException('Media must be uploaded from this restaurant customer app workspace.');
        }
    }

    private function assertArrayBudget(array $value, string $label, int $maxKeys, int $maxBytes): void
    {
        if (count($value) > $maxKeys) {
            throw new InvalidArgumentException("{$label} can contain at most {$maxKeys} entries.");
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > $maxBytes) {
            throw new InvalidArgumentException("{$label} is too large.");
        }
    }

    private function validateCta(string $action, mixed $target): void
    {
        if ($action === CustomerAppContentItem::CTA_EXTERNAL_URL) {
            $this->assertSafeExternalUrl((string) $target);
        }

        if (in_array($action, [CustomerAppContentItem::CTA_CALL_RESTAURANT, CustomerAppContentItem::CTA_WHATSAPP], true)
            && ! preg_match('/^\+?[0-9 ()-]{7,20}$/', (string) $target)) {
            throw new InvalidArgumentException('Provide a valid phone number for this action.');
        }
    }

    private function assertSafeExternalUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Only public http or https URLs are allowed.');
        }

        if ($host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.invalid')) {
            throw new InvalidArgumentException('Internal URLs are not allowed.');
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP)
            && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new InvalidArgumentException('Private or reserved network URLs are not allowed.');
        }
    }

    private function applyPublicationState(CustomerAppContentItem $item): void
    {
        $startsAt = blank($item->starts_at) ? null : Carbon::parse($item->starts_at);

        if ($item->status === CustomerAppContentItem::STATUS_PUBLISHED && blank($item->published_at)) {
            $item->published_at = now();
        }

        if ($item->status === CustomerAppContentItem::STATUS_UNPUBLISHED) {
            $item->is_active = false;
        }

        if ($startsAt instanceof Carbon && $startsAt->isFuture() && $item->status === CustomerAppContentItem::STATUS_PUBLISHED) {
            $item->status = CustomerAppContentItem::STATUS_SCHEDULED;
            $item->published_at = null;
        }
    }

    private function contentSummary(Tenant $tenant): array
    {
        $rows = CustomerAppContentItem::query()
            ->forTenant($tenant)
            ->selectRaw('type, status, count(*) as aggregate')
            ->groupBy('type', 'status')
            ->get();

        $summary = [];
        foreach (CustomerAppContentItem::types() as $type) {
            $summary[$type] = ['total' => 0, 'published' => 0, 'draft' => 0, 'scheduled' => 0, 'expired' => 0, 'unpublished' => 0];
        }

        foreach ($rows as $row) {
            $summary[$row->type]['total'] += (int) $row->aggregate;
            $summary[$row->type][$row->status] = (int) $row->aggregate;
        }

        return $summary;
    }

    private function guide(Tenant $tenant, CustomerAppSetting $settings, ?CustomerAppBuild $build, ?CustomerAppRegistration $registration): array
    {
        $content = $this->contentSummary($tenant);
        $hasPublished = CustomerAppContentItem::query()->forTenant($tenant)->runtimeVisible()->exists();

        return [
            ['key' => 'enable_app', 'title' => 'Enable customer app', 'status' => $settings->app_enabled ? 'complete' : 'todo', 'action' => 'Open settings'],
            ['key' => 'branding', 'title' => 'Complete branding', 'status' => $registration ? 'complete' : 'todo', 'action' => 'Review branding'],
            ['key' => 'modes', 'title' => 'Configure order modes', 'status' => ($settings->delivery_enabled || $settings->pickup_enabled || $settings->dine_in_enabled) ? 'complete' : 'todo', 'action' => 'Open settings'],
            ['key' => 'slider', 'title' => 'Create home slider', 'status' => ($content[CustomerAppContentItem::TYPE_SLIDER]['total'] ?? 0) > 0 ? 'complete' : 'todo', 'action' => 'Create slider'],
            ['key' => 'offer', 'title' => 'Create offer', 'status' => ($content[CustomerAppContentItem::TYPE_OFFER]['total'] ?? 0) > 0 ? 'complete' : 'optional', 'action' => 'Create offer'],
            ['key' => 'event', 'title' => 'Create event', 'status' => ($content[CustomerAppContentItem::TYPE_EVENT]['total'] ?? 0) > 0 ? 'complete' : 'optional', 'action' => 'Create event'],
            ['key' => 'preview', 'title' => 'Preview customer content', 'status' => $hasPublished ? 'complete' : 'todo', 'action' => 'Open preview'],
            ['key' => 'publish', 'title' => 'Publish changes', 'status' => $settings->published_at ? 'complete' : 'todo', 'action' => 'Publish changes'],
            ['key' => 'build', 'title' => 'Generate APK only if needed', 'status' => $build ? $build->status : 'optional', 'action' => 'Generate build'],
        ];
    }

    private function capabilities(CustomerAppSetting $settings): array
    {
        return [
            'sliders' => (bool) $settings->sliders_enabled,
            'offers' => (bool) $settings->offers_enabled,
            'events' => (bool) $settings->events_enabled,
            'registration' => (bool) $settings->customer_registration_enabled,
            'guest_checkout' => (bool) $settings->guest_checkout_enabled,
            'delivery' => (bool) $settings->delivery_enabled,
            'pickup' => (bool) $settings->pickup_enabled,
            'dine_in' => (bool) $settings->dine_in_enabled,
            'table_qr' => (bool) $settings->table_qr_enabled,
            'order_tracking' => (bool) $settings->order_tracking_enabled,
            'notifications' => (bool) $settings->notifications_enabled,
        ];
    }

    private function presentRegistration(CustomerAppRegistration $registration): array
    {
        $registration->loadMissing('tenant');
        $settings = (array) $registration->tenant?->settings;

        return [
            'uuid' => $registration->uuid,
            'package_id' => $registration->package_id,
            'display_name' => $registration->display_name,
            'platform' => $registration->platform,
            'status' => $registration->status,
            'branding_revision' => (int) $registration->branding_revision,
            'branding' => [
                'logo_url' => data_get($settings, 'branding.logo_url') ?? data_get($settings, 'logo_url') ?? data_get($settings, 'restaurant_logo_url'),
                'app_icon_url' => data_get($settings, 'app_icon_url') ?? data_get($settings, 'branding.app_icon_url'),
                'splash_logo_url' => data_get($settings, 'splash_logo_url'),
                'primary_color' => data_get($settings, 'primary_color') ?? data_get($settings, 'theme_primary_color'),
                'secondary_color' => data_get($settings, 'secondary_color') ?? data_get($settings, 'theme_secondary_color'),
            ],
        ];
    }

    private function touchContent(Tenant $tenant): void
    {
        $this->settings($tenant)->forceFill(['content_updated_at' => now()])->save();
    }

    private function publicUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    private function assertOwns(Tenant $tenant, CustomerAppContentItem $item): void
    {
        abort_unless((int) $item->tenant_id === (int) $tenant->id, 404, 'Customer app content was not found for this restaurant.');
    }

    private function audit(string $event, Tenant $tenant, ?User $actor, array $properties = []): void
    {
        try {
            activity('customer-app')
                ->causedBy($actor)
                ->performedOn($tenant)
                ->withProperties($properties + ['tenant_id' => $tenant->id])
                ->event($event)
                ->log($event);
        } catch (\Throwable) {
            // Audit logging must never break content publishing.
        }
    }
}
