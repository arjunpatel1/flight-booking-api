<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Services\Servicing\FlightRefundPolicyService;
use Modules\Saas\Models\Tenant;
use Modules\Support\ApiResponse;

class FlightBookingSettingsController extends Controller
{
    private const FLIGHT_CONFIGURATION_KEYS = [
        'route_catalog',
        'route_pnrs',
        'inventory_overrides',
        'ancillaries_catalog',
        'fare_family_overrides',
    ];

    private const DEFAULT_TICKET_ADVISORY = "Passengers should arrive at the departure terminal at least 2 hours before domestic flights and 3 hours before international flights.\nCarry valid government-issued photo identification for airport entry and security clearance.\nCheck-in counters close 60 minutes before departure and boarding gates close 20 minutes before departure.\nBaggage allowances, changes, cancellations, and refunds are subject to the selected fare family and the operating carrier's conditions of carriage.";

    public function show(Request $request): JsonResponse
    {
        $tenant = $this->tenantFor($request);

        return ApiResponse::success([
            'minimum_advance_booking_days' => (int) data_get(
                $tenant->settings,
                'flight_booking.minimum_advance_booking_days',
                0
            ),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'minimum_advance_booking_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $tenant = $this->tenantFor($request);
        $settings = (array) ($tenant->settings ?? []);
        $flightSettings = (array) ($settings['flight_booking'] ?? []);
        $flightSettings['minimum_advance_booking_days'] = (int) $data['minimum_advance_booking_days'];
        $settings['flight_booking'] = $flightSettings;
        $tenant->forceFill(['settings' => $settings])->save();

        return ApiResponse::updated([
            'minimum_advance_booking_days' => $flightSettings['minimum_advance_booking_days'],
        ], 'Flight booking settings saved.');
    }

    /** Tenant cancellation & refund policy (defaults until the tenant saves its own). */
    public function showRefundPolicy(Request $request, FlightRefundPolicyService $policies): JsonResponse
    {
        $tenant = $this->tenantFor($request);

        return ApiResponse::success([
            'policy' => $policies->policyFor($tenant),
            'defaults' => $policies->normalize(FlightRefundPolicyService::DEFAULT_POLICY),
            'is_customised' => data_get($tenant->settings, 'flight_booking.refund_policy') !== null,
        ]);
    }

    public function updateRefundPolicy(Request $request, FlightRefundPolicyService $policies): JsonResponse
    {
        $data = $request->validate(FlightRefundPolicyService::rules());
        $policy = $policies->normalize($data);

        $tenant = $this->tenantFor($request);
        $settings = (array) ($tenant->settings ?? []);
        $flightSettings = (array) ($settings['flight_booking'] ?? []);
        $flightSettings['refund_policy'] = $policy;
        $settings['flight_booking'] = $flightSettings;
        $tenant->forceFill(['settings' => $settings])->save();

        return ApiResponse::updated(['policy' => $policy, 'is_customised' => true], 'Refund policy saved.');
    }

    public function showTicketAdvisory(Request $request): JsonResponse
    {
        $tenant = $this->tenantFor($request);

        return ApiResponse::success([
            'ticket_advisory' => (string) data_get(
                $tenant->settings,
                'flight_booking.ticket_advisory',
                self::DEFAULT_TICKET_ADVISORY
            ),
        ]);
    }

    public function updateTicketAdvisory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ticket_advisory' => ['required', 'string', 'max:12000'],
        ]);

        $tenant = $this->tenantFor($request);
        $settings = (array) ($tenant->settings ?? []);
        $flightSettings = (array) ($settings['flight_booking'] ?? []);
        $flightSettings['ticket_advisory'] = trim($data['ticket_advisory']);
        $settings['flight_booking'] = $flightSettings;
        $tenant->forceFill(['settings' => $settings])->save();

        return ApiResponse::updated([
            'ticket_advisory' => $flightSettings['ticket_advisory'],
        ], 'E-ticket travel advisory saved.');
    }

    /** Read tenant-scoped flight configuration used by legacy flight screens. */
    public function showConfiguration(Request $request, string $key): JsonResponse
    {
        abort_unless(in_array($key, self::FLIGHT_CONFIGURATION_KEYS, true), 404);
        $tenant = $this->tenantForRead($request);
        $value = data_get($tenant->settings, "flight_booking.configurations.{$key}");

        return ApiResponse::success([
            'key' => $key,
            'value' => $value,
            'exists' => $value !== null,
        ]);
    }

    /** Persist tenant-scoped flight configuration in the database. */
    public function updateConfiguration(Request $request, string $key): JsonResponse
    {
        abort_unless(in_array($key, self::FLIGHT_CONFIGURATION_KEYS, true), 404);
        $validated = $request->validate(['value' => ['present', 'array', 'max:10000']]);
        $tenant = $this->tenantFor($request);
        $savedValue = DB::transaction(function () use ($tenant, $key, $validated) {
            $lockedTenant = Tenant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($tenant->id);
            $settings = (array) ($lockedTenant->settings ?? []);
            $flightSettings = (array) ($settings['flight_booking'] ?? []);
            $configurations = (array) ($flightSettings['configurations'] ?? []);
            $configurations[$key] = $validated['value'];
            $flightSettings['configurations'] = $configurations;
            $settings['flight_booking'] = $flightSettings;
            $lockedTenant->forceFill(['settings' => $settings])->save();

            return $configurations[$key];
        });

        return ApiResponse::success([
            'key' => $key,
            'value' => $savedValue,
            'exists' => true,
        ], 'Flight configuration saved.');
    }

    public static function defaultTicketAdvisory(): string
    {
        return self::DEFAULT_TICKET_ADVISORY;
    }

    private function tenantFor(Request $request): Tenant
    {
        $tenantId = $request->user()?->tenant_id
            ?? $request->attributes->get('tenant_id')
            ?? $request->attributes->get('tenant')?->id;

        abort_unless($tenantId, 403, 'A tenant admin account is required to manage flight booking settings.');

        return Tenant::query()->withoutGlobalScopes()->findOrFail($tenantId);
    }

    private function tenantForRead(Request $request): Tenant
    {
        $tenantId = $request->user()?->tenant_id
            ?? $request->attributes->get('tenant_id')
            ?? $request->attributes->get('tenant')?->id
            ?? $request->header('X-Tenant-ID')
            ?? $request->query('tenant_id');

        abort_unless($tenantId, 400, 'A tenant identifier is required to load flight configuration.');

        return Tenant::query()->withoutGlobalScopes()->findOrFail((int) $tenantId);
    }
}
