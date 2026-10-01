<?php

namespace Modules\Setting\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Branch\Models\Branch;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Services\Delivery\DeliveryGeocoder;
use Modules\Support\ApiResponse;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;
use Modules\Order\Delivery\CustomerDeliveryQuote;
use Modules\Order\Delivery\DeliveryAvailability;
use Modules\Order\Delivery\DeliveryLocation;
use Modules\Order\Delivery\DeliveryProvider;
use Modules\Order\Delivery\ProviderUnavailable;
use Illuminate\Support\Str;
use Modules\ActivityLog\Models\ActivityLog;

class DeliveryLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $branches = Branch::withoutGlobalActive()->where('tenant_id', $tenantId)->orderByDesc('is_main')->orderBy('name')->get()
            ->map(fn (Branch $branch) => $this->branchPayload($branch));

        return ApiResponse::success(['branches' => $branches]);
    }

    public function platformIndex(): JsonResponse
    {
        abort_unless(\Modules\Setting\Services\Setting\DeliverySettingAccess::platformAdministrator(), 403);
        $branches = Branch::query()->withoutGlobalScopes()->with('tenant')->orderBy('tenant_id')->orderByDesc('is_main')->orderBy('name')->get()
            ->map(fn (Branch $branch) => array_merge($this->branchPayload($branch), [
                'tenant_name' => $branch->tenant?->name,
            ]));

        return ApiResponse::success(['branches' => $branches]);
    }

    public function platformServiceability(Request $request, CustomerDeliveryQuote $customerQuote,
        DeliveryAvailability $availability, DeliveryProvider $provider): JsonResponse
    {
        abort_unless(\Modules\Setting\Services\Setting\DeliverySettingAccess::platformAdministrator(), 403);
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail((int) $request->input('branch_id'));
        app(TenantContext::class)->set((int) $branch->tenant_id);

        return $this->serviceability($request, $customerQuote, $availability, $provider);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        abort_unless((int) $branch->tenant_id === $this->tenantId($request), 404);
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'delivery_radius_km' => ['required', 'numeric', 'between:0.1,500'],
            'delivery_assignment_timeout_minutes' => ['required', 'integer', 'between:5,120'],
            'enable_whatsapp_delivery' => ['sometimes', 'boolean'],
            'address_line1' => ['required', 'string', 'max:190'],
            'address_line2' => ['nullable', 'string', 'max:190'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'postal_code' => ['required', 'string', 'max:20'],
        ]);
        abort_if(($data['enable_whatsapp_delivery'] ?? false) && ! collect($branch->order_types)
            ->contains(fn ($type) => ($type instanceof \BackedEnum ? $type->value : $type) === 'delivery'),
            422, 'Enable Delivery in this branch order types before showing Home Delivery in WhatsApp.');
        $branch->forceFill([
            'latitude' => $data['latitude'], 'longitude' => $data['longitude'],
            'delivery_radius_km' => $data['delivery_radius_km'],
            'delivery_assignment_timeout_minutes' => $data['delivery_assignment_timeout_minutes'],
            'address_line1' => trim($data['address_line1']),
            'address_line2' => trim((string) ($data['address_line2'] ?? '')) ?: null,
            'city' => trim($data['city']),
            'state' => trim($data['state']),
            'postal_code' => trim($data['postal_code']),
        ])->save();

        if (array_key_exists('enable_whatsapp_delivery', $data)) {
            WhatsAppTenantAssignment::query()->where('tenant_id', $branch->tenant_id)->where('is_active', true)
                ->get()->each(function (WhatsAppTenantAssignment $assignment) use ($data): void {
                    $capabilities = $assignment->capabilities ?: [];
                    $types = collect($capabilities['enabled_order_types'] ?? ['takeaway']);
                    $types = $data['enable_whatsapp_delivery'] ? $types->push('delivery') : $types->reject(fn ($type) => $type === 'delivery');
                    $capabilities['enabled_order_types'] = $types->push('takeaway')->unique()->values()->all();
                    $assignment->update(['capabilities' => $capabilities]);
                });
        }

        return ApiResponse::success($this->branchPayload($branch->fresh()));
    }

    public function history(Request $request, Branch $branch): JsonResponse
    {
        abort_unless((int) $branch->tenant_id === $this->tenantId($request), 404);
        $fields = ['latitude', 'longitude', 'delivery_radius_km', 'delivery_assignment_timeout_minutes',
            'address_line1', 'address_line2', 'city', 'state', 'postal_code'];
        $records = ActivityLog::query()->with('causer:id,name')
            ->where('subject_type', Branch::class)->where('subject_id', $branch->id)
            ->where('event', 'updated')->latest('id')->limit(50)->get()
            ->map(function (ActivityLog $activity) use ($fields): ?array {
                $properties = is_object($activity->properties) && method_exists($activity->properties, 'toArray')
                    ? $activity->properties->toArray() : (array) $activity->properties;
                $old = array_intersect_key((array) ($properties['old'] ?? []), array_flip($fields));
                $new = array_intersect_key((array) ($properties['attributes'] ?? []), array_flip($fields));
                $changed = collect($fields)->contains(fn (string $field) => ($old[$field] ?? null) != ($new[$field] ?? null));
                if (! $changed) return null;

                return [
                    'id' => $activity->id,
                    'changed_at' => $activity->created_at?->toIso8601String(),
                    'changed_by' => $activity->causer?->name ?: 'System',
                    'from' => $old,
                    'to' => $new,
                ];
            })->filter()->take(20)->values();

        return ApiResponse::success(['history' => $records]);
    }

    public function search(Request $request, DeliveryGeocoder $geocoder): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'required_without_all:latitude,longitude', 'string', 'min:3', 'max:200'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
        ]);
        $results = isset($data['latitude'], $data['longitude'])
            ? $geocoder->reverse((float) $data['latitude'], (float) $data['longitude'])
            : $geocoder->search($data['q']);

        return ApiResponse::success(['results' => $results]);
    }

    public function serviceability(Request $request, CustomerDeliveryQuote $customerQuote,
        DeliveryAvailability $availability, DeliveryProvider $provider): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'pickup_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $branch = Branch::withoutGlobalActive()->where('tenant_id', $this->tenantId($request))
            ->findOrFail($data['branch_id']);
        if (array_key_exists('pickup_latitude', $data) || array_key_exists('pickup_longitude', $data)) {
            abort_unless(\Modules\Setting\Services\Setting\DeliverySettingAccess::platformAdministrator(), 403);
            abort_unless(isset($data['pickup_latitude'], $data['pickup_longitude']), 422,
                'Set both restaurant latitude and longitude.');
            $branch = $branch->replicate()->forceFill([
                'latitude' => $data['pickup_latitude'], 'longitude' => $data['pickup_longitude'],
            ]);
        }
        $schedule = $availability->status($branch);
        $local = $customerQuote->calculate($branch, $data, 0, $customerQuote->settings());
        $pickup = DeliveryLocation::fromAddress(['latitude' => $branch->latitude, 'longitude' => $branch->longitude]);
        $dropoff = DeliveryLocation::fromAddress($data);
        $providerResult = ['checked' => false, 'available' => null, 'cost' => null, 'reason' => null];
        if ($pickup && $dropoff) {
            try {
                $quotes = $provider->quotes($pickup, $dropoff, 'ADMIN-CHECK-'.Str::uuid(), true);
                $serviceable = collect($quotes)->first(fn ($quote) => $quote->serviceable);
                $providerResult = ['checked' => true, 'available' => (bool) $serviceable,
                    'cost' => $serviceable?->cost, 'reason' => $serviceable ? null : 'OUTSIDE_PROVIDER_COVERAGE'];
            } catch (ProviderUnavailable $exception) {
                $providerResult = ['checked' => true, 'available' => false, 'cost' => null,
                    'reason' => $exception->reasonCode];
            }
        }
        $available = (bool) $schedule['available'] && (bool) $local['serviceable']
            && $providerResult['available'] === true;

        return ApiResponse::success([
            'available' => $available,
            'message' => $available ? 'Delivery service is available for this route.' : 'Delivery service is not available for this route.',
            'distance_km' => $local['distance_km'],
            'customer_fee' => $local['delivery_fee'],
            'provider_cost' => $providerResult['cost'],
            'restaurant_contribution' => $providerResult['cost'] === null || $local['delivery_fee'] === null
                ? null : max(0, round((float) $providerResult['cost'] - (float) $local['delivery_fee'], 2)),
            'schedule' => $schedule,
            'local_coverage' => ['available' => (bool) $local['serviceable'], 'code' => $local['failure_code']],
            'provider' => $providerResult,
        ]);
    }

    private function tenantId(Request $request): int
    {
        $id = $request->user()?->tenantId() ?? app(TenantContext::class)->id();
        abort_unless($id, 403, 'Open a restaurant workspace first.');
        return (int) $id;
    }

    private function branchPayload(Branch $branch): array
    {
        return ['id' => $branch->id, 'name' => $branch->name, 'address' => collect([$branch->address_line1, $branch->address_line2, $branch->city, $branch->state, $branch->postal_code])->filter()->join(', '),
            'address_line1' => $branch->address_line1, 'address_line2' => $branch->address_line2, 'city' => $branch->city, 'state' => $branch->state, 'postal_code' => $branch->postal_code,
            'latitude' => $branch->latitude, 'longitude' => $branch->longitude, 'delivery_radius_km' => $branch->delivery_radius_km, 'delivery_assignment_timeout_minutes' => $branch->delivery_assignment_timeout_minutes ?: 15,
            'delivery_order_type_enabled' => collect($branch->order_types)->contains(fn ($type) => ($type instanceof \BackedEnum ? $type->value : $type) === 'delivery')];
    }
}
