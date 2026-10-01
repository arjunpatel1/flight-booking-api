<?php

namespace Modules\Setting\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Enums\SettingSection;
use Modules\Setting\Http\Requests\Api\V1\SaveSettingRequest;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Modules\Support\ApiResponse;

class SettingController extends Controller
{
    /**
     * Create a new instance of SettingController
     */
    public function __construct(protected SettingServiceInterface $service) {}

    /**
     * Get settings and meta for a section
     */
    public function index(SettingSection $section): JsonResponse
    {
        $this->authorizePlatformSection($section);

        $settings = $this->service->getSettings($section);
        if ($section === SettingSection::Delivery && ! \Modules\Setting\Services\Setting\DeliverySettingAccess::platformAdministrator()) {
            $settings = Arr::only($settings, \Modules\Setting\Services\Setting\DeliverySettingAccess::TENANT_KEYS);
        }
        return ApiResponse::success([
            'settings' => $settings,
            'meta' => $this->service->getMeta($section),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(SaveSettingRequest $request, SettingSection $section): JsonResponse
    {
        $this->authorizePlatformSection($section);

        $this->service->update($section, $request->validated());
        $this->invalidateFrontendCaches();

        return ApiResponse::success(
            ['app_settings' => $this->service->getAppSettings(true)],
            __(
                'admin::messages.resource_updated',
                [
                    'resource' => __('setting::settings.settings'),
                ]
            )
        );
    }

    /**
     * Toggle operational WhatsApp alerts without round-tripping provider
     * credentials or the complete template catalogue through the browser.
     */
    public function updateWhatsAppAlerts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'delivery_alerts_enabled' => ['required', 'boolean'],
        ]);

        setting([
            'whatsapp_delivery_alerts_enabled' => $data['delivery_alerts_enabled'],
        ]);
        $this->invalidateFrontendCaches();

        return ApiResponse::success([
            'whatsapp_delivery_alerts_enabled' => (bool) $data['delivery_alerts_enabled'],
        ]);
    }

    public function appearance(): JsonResponse
    {
        return $this->index(SettingSection::Appearance);
    }

    public function updateAppearance(SaveSettingRequest $request): JsonResponse
    {
        return $this->update($request, SettingSection::Appearance);
    }

    public function systemConfiguration(): JsonResponse
    {
        return $this->index(SettingSection::SystemConfiguration);
    }

    public function updateSystemConfiguration(SaveSettingRequest $request): JsonResponse
    {
        return $this->update($request, SettingSection::SystemConfiguration);
    }

    public function firebaseHealth(): JsonResponse
    {
        $this->authorizePlatformSection(SettingSection::Firebase);

        $settings = $this->service->getSettings(SettingSection::Firebase);
        $serviceAccountJson = Arr::get($settings, 'encryptable.firebase_service_account_json');
        $configCredentials = config('services.firebase.credentials');
        $projectId = setting('firebase_project_id') ?: config('services.firebase.project_id');
        $enabled = (bool) setting('firebase_enabled', filled($configCredentials));
        $serviceAccountValid = filled($configCredentials);

        if (filled($serviceAccountJson)) {
            json_decode($serviceAccountJson, true);
            $serviceAccountValid = json_last_error() === JSON_ERROR_NONE;
        }

        $checks = [
            [
                'key' => 'firebase_enabled',
                'label' => 'Firebase enabled',
                'status' => $enabled ? 'ok' : 'warning',
                'message' => $enabled ? 'Firebase is enabled.' : 'Firebase is disabled.',
            ],
            [
                'key' => 'project_id',
                'label' => 'Project ID',
                'status' => filled($projectId) ? 'ok' : 'error',
                'message' => filled($projectId) ? 'Project ID is configured.' : 'Project ID is required when Firebase is enabled.',
            ],
            [
                'key' => 'service_account',
                'label' => 'Service account',
                'status' => $serviceAccountValid ? 'ok' : 'error',
                'message' => $serviceAccountValid
                    ? 'Service account is configured.'
                    : 'Service account JSON is missing or invalid.',
            ],
            [
                'key' => 'web_push',
                'label' => 'Web push',
                'status' => Arr::get($settings, 'web_push_configured') ? 'ok' : 'warning',
                'message' => Arr::get($settings, 'web_push_configured')
                    ? 'Web push client keys are configured.'
                    : 'Web push client keys are incomplete.',
            ],
        ];

        $hasError = collect($checks)->contains(fn (array $check): bool => $check['status'] === 'error' && $enabled);
        $hasWarning = collect($checks)->contains(fn (array $check): bool => $check['status'] === 'warning');

        return ApiResponse::success([
            'status' => $hasError ? 'error' : ($hasWarning ? 'warning' : 'ok'),
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ]);
    }

    /**
     * Infrastructure credentials are platform-owned. Restaurant administrators
     * may configure their branding and operations, but must never read or
     * mutate shared Firebase, mail, filesystem, or host configuration.
     */
    private function authorizePlatformSection(SettingSection $section): void
    {
        if (! in_array($section, [
            SettingSection::Firebase,
            SettingSection::Mail,
            SettingSection::Filesystem,
            SettingSection::SystemConfiguration,
        ], true)) {
            return;
        }

        $user = Auth::user();
        $isPlatformAdmin = $user
            && $user->tenant_id === null
            && $user->branch_id === null
            && $user->hasRole('super_admin');

        abort_unless($isPlatformAdmin, 403, 'This setting is managed by the NexDine platform.');
    }

    /**
     * This method retrieves and returns a list of Setting public models.
     */
    public function getAppSettings(Request $request): JsonResponse
    {
        $includeLogoData = $request->boolean('include_logo_data');
        $cacheKey = makeCacheKey([
            'app',
            'settings',
            'public',
            app()->bound(TenantContext::class) ? (app(TenantContext::class)->id() ?: 'platform') : 'platform',
            app()->getLocale(),
            $includeLogoData ? 'with-logo-data' : 'lean',
        ], false);

        $settings = Cache::tags('settings')->remember(
            $cacheKey,
            now()->addMinutes((int) setting('boot_data_cache_minutes', 5)),
            fn () => $this->service->getAppSettings(includeLogoData: $includeLogoData)
        );

        return ApiResponse::success($settings);
    }

    /**
     * Get cached boot data for frontend initialization.
     */
    public function bootData(): JsonResponse
    {
        $versionKey = makeCacheKey(['app', 'boot_data_version'], false);
        $version = (int) Cache::get($versionKey, 1);
        $cacheKey = makeCacheKey([
            'app',
            'boot_data',
            'safe-v2',
            $version,
            app()->getLocale(),
            Auth::check() ? Auth::id() : 'guest',
        ], false);
        $ttl = (int) setting('boot_data_cache_minutes', 5);

        $data = Cache::remember($cacheKey, now()->addMinutes($ttl), fn () => [
            'app_settings' => $this->service->getAppSettings(),
            'general_settings' => $this->service->getSettings(SettingSection::General),
            'appearance_settings' => $this->service->getSettings(SettingSection::Appearance),
            'currency_settings' => Arr::only(
                $this->service->getSettings(SettingSection::Currency),
                ['supported_currencies', 'default_currency']
            ),
        ]);

        return ApiResponse::success($data);
    }

    /**
     * Clear boot data cache.
     */
    public function clearBootCache(): JsonResponse
    {
        $this->invalidateFrontendCaches(flushSharedSettings: false);

        return ApiResponse::success(message: __('setting::settings.boot_cache_cleared'));
    }

    private function invalidateFrontendCaches(bool $flushSharedSettings = true): void
    {
        if ($flushSharedSettings) {
            Cache::tags('settings')->flush();
        }

        $versionKey = makeCacheKey(['app', 'boot_data_version'], false);
        Cache::forever($versionKey, ((int) Cache::get($versionKey, 1)) + 1);
        $this->service->refreshSettingBinding();
    }
}
