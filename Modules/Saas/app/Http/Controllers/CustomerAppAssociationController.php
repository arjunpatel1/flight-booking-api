<?php

namespace Modules\Saas\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Models\CustomerAppRegistration;

class CustomerAppAssociationController extends Controller
{
    public function android(): JsonResponse
    {
        $targets = CustomerAppRegistration::query()
            ->withoutGlobalScopes()
            ->active()
            ->where('platform', CustomerAppRegistration::PLATFORM_ANDROID)
            ->whereNotNull('signing_certificate_fingerprint')
            ->get(['package_id', 'signing_certificate_fingerprint'])
            ->map(function (CustomerAppRegistration $registration): ?array {
                $fingerprint = strtoupper(preg_replace('/[^0-9A-F]/i', '', (string) $registration->signing_certificate_fingerprint));
                if (preg_match('/\A[0-9A-F]{64}\z/', $fingerprint) !== 1) {
                    return null;
                }

                return [
                    'relation' => ['delegate_permission/common.handle_all_urls'],
                    'target' => [
                        'namespace' => 'android_app',
                        'package_name' => $registration->package_id,
                        'sha256_cert_fingerprints' => [implode(':', str_split($fingerprint, 2))],
                    ],
                ];
            })
            ->filter()
            ->unique(fn (array $entry) => $entry['target']['package_name'].'|'.$entry['target']['sha256_cert_fingerprints'][0])
            ->values();

        abort_if($targets->isEmpty(), 503, 'Android application links are not configured.');

        return response()->json($targets->all(), 200, [
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function apple(): JsonResponse
    {
        $teamId = strtoupper(trim((string) config('saas.customer_app_links.apple_team_id')));
        abort_unless(preg_match('/\A[A-Z0-9]{10}\z/', $teamId) === 1, 503, 'Apple universal links are not configured.');

        $details = CustomerAppRegistration::query()
            ->withoutGlobalScopes()
            ->active()
            ->where('platform', CustomerAppRegistration::PLATFORM_IOS)
            ->pluck('package_id')
            ->filter(fn ($bundleId) => preg_match('/\A[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+){2,}\z/', (string) $bundleId) === 1)
            ->unique()
            ->map(fn ($bundleId) => [
                'appID' => $teamId.'.'.$bundleId,
                'components' => [[
                    '/' => config('saas.customer_app_links.invite_path'),
                    '?' => ['invite' => '?*'],
                    'comment' => 'Authenticated NexDine group-order invitation',
                ]],
            ])
            ->values();

        abort_if($details->isEmpty(), 503, 'Apple universal links are not configured.');

        return response()->json([
            'applinks' => ['apps' => [], 'details' => $details->all()],
        ], 200, [
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
