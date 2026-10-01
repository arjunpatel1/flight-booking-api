<?php

namespace Modules\Saas\Services\CustomerApp;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Saas\Exceptions\CustomerAppAuthorizationException;
use Modules\Saas\Models\CustomerAppBuild;
use Modules\Saas\Models\CustomerAppRegistration;
use Modules\Saas\Models\Tenant;
use Modules\User\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerAppBuildService
{
    public function __construct(
        private readonly CustomerAppAuthorizationService $authorization,
        private readonly CustomerAppBuildSnapshotValidator $snapshots,
    ) {}

    public function request(User $administrator, Tenant $tenant, array $input, bool $allowExistingVersion = false): array
    {
        $platform = strtolower($input['platform']);
        $type = strtolower($input['build_type']);
        if ($type === 'app_bundle' && ! config('saas.customer_app_build.supports_aab', false)) {
            throw new CustomerAppAuthorizationException(
                'AAB_NOT_SUPPORTED',
                'Android App Bundle generation is not enabled on the controlled build worker.',
                422,
            );
        }
        $this->authorization->assertBuildAccess($administrator, $tenant, $type === 'app_bundle');
        return DB::transaction(function () use ($administrator, $tenant, $input, $platform, $type, $allowExistingVersion): array {
            // Serialize build admission per tenant. This prevents concurrent
            // requests from bypassing the active-build capacity check.
            $tenant = Tenant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($tenant->id);
            if (! $allowExistingVersion && ! in_array($input['requested_version'], $this->versionOptions($tenant), true)) {
                throw new CustomerAppAuthorizationException('INVALID_BUILD_VERSION', 'Select one of the currently available version options.', 422);
            }
            $registration = CustomerAppRegistration::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('platform', $platform)
                ->lockForUpdate()->first();
            if (! $registration || $registration->status !== CustomerAppRegistration::STATUS_ACTIVE) {
                throw new CustomerAppAuthorizationException('APP_INACTIVE', 'An active customer application registration is required.');
            }

            $snapshot = $this->snapshot($tenant, $registration, $platform, $type, $input['requested_version']);
            $configurationRevision = $this->snapshots->revision($snapshot);
            $fingerprint = hash('sha256', implode('|', [
                $tenant->id, $registration->id, $platform, $type,
                $input['requested_version'], $configurationRevision,
            ]));

            $existing = CustomerAppBuild::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('active_fingerprint', $fingerprint)->first();
            if ($existing) {
                return ['build' => $existing->load('artifact'), 'reused' => true];
            }

            $activeCount = CustomerAppBuild::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereIn('status', CustomerAppBuild::ACTIVE_STATUSES)
                ->count();
            if ($activeCount >= max(1, (int) config('saas.customer_app_build.max_active_per_tenant', 1))) {
                throw new CustomerAppAuthorizationException(
                    'TENANT_BUILD_CAPACITY_REACHED',
                    'A customer application build is already active for this tenant.',
                    429,
                );
            }

            $build = CustomerAppBuild::query()->create([
                'tenant_id' => $tenant->id,
                'customer_app_registration_id' => $registration->id,
                'platform' => $platform,
                'build_type' => $type,
                'requested_version' => $input['requested_version'],
                'status' => CustomerAppBuild::STATUS_QUEUED,
                'source_commit' => config('saas.customer_app_build.source_commit'),
                'branding_revision' => (int) $registration->branding_revision,
                'build_config_revision' => $configurationRevision,
                'request_fingerprint' => $fingerprint,
                'active_fingerprint' => $fingerprint,
                'config_snapshot' => $snapshot,
                'requested_by' => $administrator->id,
                'queued_at' => now(),
            ]);

            activity('customer_app_build')->performedOn($build)->causedBy($administrator)
                ->withProperties(['tenant_id' => $tenant->id, 'status' => CustomerAppBuild::STATUS_QUEUED, 'platform' => $platform, 'build_type' => $type])
                ->log('Customer application build requested');

            return ['build' => $build, 'reused' => false];
        });
    }

    public function paginate(Tenant $tenant, int $perPage = 20): LengthAwarePaginator
    {
        return CustomerAppBuild::query()->withoutGlobalScopes()->with('artifact')
            ->where('tenant_id', $tenant->id)->latest('id')->paginate(min(max($perPage, 1), 50));
    }

    public function find(Tenant $tenant, string $uuid): CustomerAppBuild
    {
        $build = CustomerAppBuild::query()->withoutGlobalScopes()->with('artifact')
            ->where('tenant_id', $tenant->id)->where('uuid', $uuid)->first();
        if (! $build) {
            throw new CustomerAppAuthorizationException('BUILD_NOT_FOUND', 'Build request was not found.', 404);
        }

        return $build;
    }

    public function cancel(User $user, Tenant $tenant, string $uuid): CustomerAppBuild
    {
        $this->authorization->assertBuildAccess($user, $tenant);
        $build = DB::transaction(function () use ($user, $tenant, $uuid): CustomerAppBuild {
            $locked = CustomerAppBuild::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('uuid', $uuid)
                ->lockForUpdate()
                ->first();
            if (! $locked) {
                throw new CustomerAppAuthorizationException('BUILD_NOT_FOUND', 'Build request was not found.', 404);
            }
            if ($locked->build_type === 'app_bundle') {
                $this->authorization->assertBuildAccess($user, $tenant, true);
            }
            if (! in_array($locked->status, CustomerAppBuild::ACTIVE_STATUSES, true)) {
                throw new CustomerAppAuthorizationException('BUILD_NOT_CANCELLABLE', 'Only an active build request can be cancelled.', 409);
            }

            $running = $locked->status !== CustomerAppBuild::STATUS_QUEUED;
            $locked->forceFill($running
                ? ['status' => CustomerAppBuild::STATUS_CANCEL_REQUESTED]
                : ['status' => CustomerAppBuild::STATUS_CANCELLED, 'active_fingerprint' => null, 'cancelled_at' => now()]
            )->save();

            return $locked;
        });
        activity('customer_app_build')->performedOn($build)->causedBy($user)->log('Customer application build cancelled');

        return $build->fresh('artifact');
    }

    public function retry(User $user, Tenant $tenant, string $uuid): array
    {
        // Authorize before resolving the identifier so an unauthorized caller
        // cannot use retry as a build-record enumeration oracle.
        $this->authorization->assertBuildAccess($user, $tenant);
        $build = $this->find($tenant, $uuid);
        if ($build->build_type === 'app_bundle') {
            $this->authorization->assertBuildAccess($user, $tenant, true);
        }
        if (! in_array($build->status, [CustomerAppBuild::STATUS_FAILED, CustomerAppBuild::STATUS_CANCELLED], true)) {
            throw new CustomerAppAuthorizationException('BUILD_NOT_RETRYABLE', 'Only failed or cancelled builds can be retried.', 409);
        }

        return $this->request($user, $tenant, [
            'platform' => $build->platform, 'build_type' => $build->build_type,
            'requested_version' => $build->requested_version,
        ], true);
    }

    public function versionOptions(Tenant $tenant): array
    {
        $latest = CustomerAppBuild::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('status', CustomerAppBuild::STATUS_READY)
            ->whereNotNull('completed_at')->latest('completed_at')->value('requested_version');
        if (! preg_match('/^(\d+)\.(\d+)\.(\d+)/', (string) $latest, $matches)) return ['1.0.0'];
        [$major, $minor, $patch] = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
        return [sprintf('%d.%d.%d', $major, $minor, $patch + 1), sprintf('%d.%d.0', $major, $minor + 1), sprintf('%d.0.0', $major + 1)];
    }

    public function download(User $user, Tenant $tenant, string $uuid): StreamedResponse
    {
        // Resolve authorization before the opaque identifier and re-check the
        // AAB entitlement after resolving an owned build.
        $this->authorization->assertBuildAccess($user, $tenant);
        $build = $this->find($tenant, $uuid);
        if ($build->build_type === 'app_bundle') {
            $this->authorization->assertBuildAccess($user, $tenant, true);
        }
        $artifact = $build->artifact;
        if ($build->status !== CustomerAppBuild::STATUS_READY || ! $artifact || ! $artifact->isDownloadable()) {
            throw new CustomerAppAuthorizationException('BUILD_ARTIFACT_UNAVAILABLE', 'The private build artifact is not available.', 409);
        }
        $disk = Storage::disk($artifact->storage_disk);
        if (! $disk->exists($artifact->storage_reference)) {
            throw new CustomerAppAuthorizationException('BUILD_ARTIFACT_MISSING', 'The private build artifact could not be found.', 410);
        }

        $extension = $artifact->build_type === 'app_bundle' ? 'aab' : 'apk';
        $filename = sprintf(
            '%s-customer-%s.%s',
            str($tenant->slug)->slug()->limit(80, ''),
            str($artifact->version)->replaceMatches('/[^0-9A-Za-z.+-]/', '-'),
            $extension,
        );

        return $disk->download($artifact->storage_reference, $filename, [
            'Content-Type' => $artifact->mime_type,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function present(CustomerAppBuild $build): array
    {
        return [
            'uuid' => $build->uuid, 'platform' => $build->platform, 'build_type' => $build->build_type,
            'requested_version' => $build->requested_version, 'status' => $build->status,
            'branding_revision' => $build->branding_revision, 'build_config_revision' => $build->build_config_revision,
            'worker_connected' => (bool) config('saas.customer_app_build.worker_connected', false),
            'status_message' => config('saas.customer_app_build.worker_connected', false)
                ? 'Queued for the dedicated build worker.' : 'Build service not connected. Request is safely queued.',
            'artifact' => $build->artifact ? [
                'uuid' => $build->artifact->uuid, 'platform' => $build->artifact->platform,
                'build_type' => $build->artifact->build_type, 'version' => $build->artifact->version,
                'checksum' => $build->artifact->checksum, 'size_bytes' => $build->artifact->size_bytes,
                'revoked_at' => $build->artifact->revoked_at?->toIso8601String(),
            ] : null,
            'error' => $build->error_code ? ['code' => $build->error_code, 'message' => $build->error_message] : null,
            'queued_at' => $build->queued_at?->toIso8601String(), 'started_at' => $build->started_at?->toIso8601String(),
            'completed_at' => $build->completed_at?->toIso8601String(), 'failed_at' => $build->failed_at?->toIso8601String(),
            'created_at' => $build->created_at?->toIso8601String(),
        ];
    }

    private function snapshot(Tenant $tenant, CustomerAppRegistration $registration, string $platform, string $type, string $version): array
    {
        $packageId = trim((string) $registration->package_id);
        $settings = (array) $tenant->settings;
        $branding = [
            'display_name' => trim((string) $registration->display_name),
            'logo_url' => data_get($settings, 'branding.logo_url') ?? data_get($settings, 'logo_url') ?? data_get($settings, 'restaurant_logo_url'),
            'app_icon_url' => data_get($settings, 'app_icon_url') ?? data_get($settings, 'branding.app_icon_url'),
            'primary_color' => data_get($settings, 'primary_color') ?? data_get($settings, 'theme_primary_color'),
            'secondary_color' => data_get($settings, 'secondary_color') ?? data_get($settings, 'theme_secondary_color'),
            'splash_logo_url' => data_get($settings, 'splash_logo_url'),
        ];
        $branding['app_icon_url'] ??= $branding['logo_url'];
        $required = ['display_name', 'logo_url', 'app_icon_url', 'primary_color', 'secondary_color'];
        if (config('saas.customer_app_build.require_splash')) {
            $required[] = 'splash_logo_url';
        }
        $missing = array_values(array_filter($required, fn ($key) => blank($branding[$key] ?? null)));
        if ($missing) {
            throw new CustomerAppAuthorizationException('BRANDING_INCOMPLETE', 'Complete required branding before requesting a build: '.implode(', ', $missing).'.', 422);
        }

        return $this->snapshots->validate([
            'schema_version' => 2,
            'tenant' => ['uuid' => $tenant->uuid ?? null, 'slug' => $tenant->slug],
            'application' => ['uuid' => $registration->uuid, 'package_id' => $packageId, 'platform' => $platform],
            'build' => ['type' => $type, 'version' => $version],
            'branding' => $branding,
            'branding_revision' => (int) $registration->branding_revision,
            'api_origin' => rtrim(trim((string) config('saas.customer_app.api_origin')), '/'),
            'source' => [
                'repository' => trim((string) config('saas.customer_app_build.repository')),
                'commit' => strtolower(trim((string) config('saas.customer_app_build.source_commit'))),
            ],
            'signing' => ['certificate_sha256' => strtolower(trim((string) config('saas.customer_app_build.signer_sha256')))],
            'toolchain' => [
                'flutter' => trim((string) config('saas.customer_app_build.flutter_version')),
                'android_sdk' => trim((string) config('saas.customer_app_build.android_sdk_version')),
            ],
        ]);
    }
}
