<?php

namespace Modules\Flight\Services\Branding;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Models\Media;
use Throwable;

/**
 * The logo an agency uploaded in System Settings → Logo, for documents sent
 * outside the web app (emails, the e-ticket PDF). Read straight from the
 * tenant's settings row, so it works from queue workers that have no tenant
 * context.
 */
class FlightTenantBrand
{
    /**
     * @return array{path: string, mime: string, url: ?string}|null
     */
    public function logo(?int $tenantId): ?array
    {
        if (! $tenantId) {
            return null;
        }

        try {
            $payload = DB::table('settings')
                ->where('setting_scope', "tenant:{$tenantId}")
                ->where('key', 'logo')
                ->value('payload');
            $mediaId = is_string($payload) ? @unserialize($payload, ['allowed_classes' => false]) : null;
            if (! is_numeric($mediaId)) {
                return null;
            }

            $media = Media::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->find((int) $mediaId);
            if (! $media || ! str_starts_with((string) $media->mime_type, 'image/')) {
                return null;
            }

            $disk = Storage::disk($media->disk ?: 'public');
            if (! $disk->exists($media->path)) {
                return null;
            }

            return [
                'path' => $disk->path($media->path),
                'mime' => (string) $media->mime_type,
                'url' => $disk->url($media->path),
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The logo as a data: URI, for HTML rendered to PDF. Without an uploaded
     * logo, the http(s) logo URL entered when the tenant was created
     * (restaurant_logo_url) is used instead.
     */
    public function logoDataUri(?int $tenantId): ?string
    {
        $logo = $this->logo($tenantId);
        $bytes = $logo ? @file_get_contents($logo['path']) : false;
        if ($bytes) {
            return 'data:' . $logo['mime'] . ';base64,' . base64_encode($bytes);
        }

        return $this->onboardingLogoUrl($tenantId);
    }

    private function onboardingLogoUrl(?int $tenantId): ?string
    {
        if (! $tenantId) {
            return null;
        }

        try {
            $payload = DB::table('settings')
                ->where('setting_scope', "tenant:{$tenantId}")
                ->where('key', 'restaurant_logo_url')
                ->value('payload');
            $url = is_string($payload) ? @unserialize($payload, ['allowed_classes' => false]) : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return is_string($url) && preg_match('#^https?://#i', trim($url)) ? trim($url) : null;
    }
}
