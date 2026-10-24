<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Models\FlightInventory;
use Modules\Support\ApiResponse;

class FlightInventoryController extends Controller
{
    /**
     * List tenant flight inventory items.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FlightInventory::query()
            ->with(['airline', 'originAirport', 'destinationAirport', 'aircraft']);

        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        if ($request->boolean('available_only', false)) {
            $query->available();
        }

        if ($airlineId = $request->query('airline_id')) {
            $query->where('airline_id', $airlineId);
        }

        if ($originId = $request->query('origin_airport_id')) {
            $query->where('origin_airport_id', $originId);
        }

        if ($destinationId = $request->query('destination_airport_id')) {
            $query->where('destination_airport_id', $destinationId);
        }

        if ($cabin = $request->query('cabin_class')) {
            $query->where('cabin_class', strtoupper($cabin));
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search', $request->query('query'))) {
            $query->search($search);
        }

        $perPage = $request->integer('per_page', 20);

        return ApiResponse::pagination($query->latest()->paginate($perPage));
    }

    /**
     * Store a new flight inventory item.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id
            ?? ($request->header('X-Tenant-ID') ? (int) $request->header('X-Tenant-ID') : null)
            ?? ($request->filled('tenant_id') ? (int) $request->input('tenant_id') : null)
            ?? 1;

        if ($request->has('cabins') && is_array($request->input('cabins'))) {
            $common = $request->validate([
                'tenant_id' => ['nullable', 'integer'],
                'airline_id' => ['required', 'exists:flight_airlines,id'],
                'flight_number' => ['required', 'string', 'max:20'],
                'origin_airport_id' => ['required', 'exists:flight_airports,id'],
                'destination_airport_id' => ['required', 'exists:flight_airports,id', 'different:origin_airport_id'],
                'departure_time' => ['required', 'string'],
                'arrival_time' => ['required', 'string'],
                'operating_days' => ['nullable', 'array'],
                'aircraft_id' => ['nullable', 'exists:flight_aircraft,id'],
                'is_refundable' => ['nullable', 'boolean'],
                'cancellation_policy' => ['nullable', 'string'],
                'reschedule_policy' => ['nullable', 'string'],
                'status' => ['nullable', 'string', 'in:active,inactive,suspended,cancelled'],
                'cabins' => ['required', 'array', 'min:0'],
                'cabins.*.cabin_class' => ['required', 'string', Rule::exists('flight_cabin_classes', 'code')->where('is_active', true)],
                'cabins.*.total_seats' => ['required', 'integer', 'min:0'],
                'cabins.*.available_seats' => ['nullable', 'integer', 'min:0'],
                'cabins.*.base_price' => ['required', 'numeric', 'min:0'],
                'cabins.*.inventory_id' => ['nullable', 'string', 'max:64'],
                'cabins.*.baggage_policy' => ['nullable', 'array'],
            ]);

            $common['tenant_id'] = $common['tenant_id'] ?? $tenantId;
            $common['flight_number'] = strtoupper($common['flight_number']);
            $flightClean = preg_replace('/[^A-Za-z0-9]/', '', $common['flight_number']);

            $updatedCabinClasses = array_map(fn($c) => strtoupper($c['cabin_class']), $common['cabins']);
            if ($request->boolean('sync_cabins', false)) {
                FlightInventory::where('tenant_id', $tenantId)
                    ->where('flight_number', $common['flight_number'])
                    ->whereNotIn('cabin_class', $updatedCabinClasses)
                    ->delete();
            }

            $results = [];
            foreach ($common['cabins'] as $cabinData) {
                $cabinClass = strtoupper($cabinData['cabin_class']);
                $cabinShort = match ($cabinClass) {
                    'PREMIUM_ECONOMY' => 'PE',
                    'BUSINESS' => 'BIZ',
                    'FIRST' => 'FST',
                    default => 'ECO',
                };

                $inventoryId = $cabinData['inventory_id'] ?? null;
                if (blank($inventoryId)) {
                    $counter = 1;
                    do {
                        $inventoryId = "INV-{$flightClean}-{$cabinShort}-" . str_pad((string) $counter, 3, '0', STR_PAD_LEFT);
                        $exists = FlightInventory::where('tenant_id', $tenantId)->where('inventory_id', $inventoryId)->exists();
                        $counter++;
                    } while ($exists);
                }

                $totalSeats = (int) $cabinData['total_seats'];
                $availableSeats = isset($cabinData['available_seats']) ? (int) $cabinData['available_seats'] : $totalSeats;

                $record = FlightInventory::updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'flight_number' => $common['flight_number'],
                        'origin_airport_id' => $common['origin_airport_id'],
                        'destination_airport_id' => $common['destination_airport_id'],
                        'departure_time' => $common['departure_time'],
                        'cabin_class' => $cabinClass,
                    ],
                    [
                        'inventory_id' => $inventoryId,
                        'airline_id' => $common['airline_id'],
                        'origin_airport_id' => $common['origin_airport_id'],
                        'destination_airport_id' => $common['destination_airport_id'],
                        'departure_time' => $common['departure_time'],
                        'arrival_time' => $common['arrival_time'],
                        'operating_days' => $common['operating_days'] ?? [1, 2, 3, 4, 5, 6, 7],
                        'aircraft_id' => $common['aircraft_id'] ?? null,
                        'total_seats' => $totalSeats,
                        'available_seats' => $availableSeats,
                        'base_price' => $cabinData['base_price'],
                        'baggage_policy' => $cabinData['baggage_policy'] ?? ['cabin_kg' => 7, 'checkin_kg' => 15],
                        'is_refundable' => $common['is_refundable'] ?? true,
                        'cancellation_policy' => $common['cancellation_policy'] ?? null,
                        'reschedule_policy' => $common['reschedule_policy'] ?? null,
                        'status' => $common['status'] ?? 'active',
                    ]
                );

                $record->load(['airline', 'originAirport', 'destinationAirport', 'aircraft']);
                $results[] = $record;
            }

            return ApiResponse::created($results, message: 'Flight inventories created successfully for all cabin classes');
        }

        $validated = $request->validate([
            'tenant_id' => ['nullable', 'integer'],
            'inventory_id' => [
                'required',
                'string',
                'max:64',
                Rule::unique('flight_inventories', 'inventory_id')->where('tenant_id', $tenantId),
            ],
            'airline_id' => ['required', 'exists:flight_airlines,id'],
            'flight_number' => ['required', 'string', 'max:20'],
            'origin_airport_id' => ['required', 'exists:flight_airports,id'],
            'destination_airport_id' => ['required', 'exists:flight_airports,id', 'different:origin_airport_id'],
            'departure_time' => ['required', 'string'],
            'arrival_time' => ['required', 'string'],
            'operating_days' => ['nullable', 'array'],
            'aircraft_id' => ['nullable', 'exists:flight_aircraft,id'],
            'cabin_class' => ['required', 'string', Rule::exists('flight_cabin_classes', 'code')->where('is_active', true)],
            'total_seats' => ['required', 'integer', 'min:0'],
            'available_seats' => ['required', 'integer', 'min:0', 'lte:total_seats'],
            'baggage_policy' => ['nullable', 'array'],
            'seat_map_configuration' => ['nullable', 'array'],
            'is_refundable' => ['nullable', 'boolean'],
            'cancellation_policy' => ['nullable', 'string'],
            'reschedule_policy' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:active,inactive,suspended,cancelled'],
            'base_price' => ['required', 'numeric', 'min:0'],
        ]);

        $validated['tenant_id'] = $validated['tenant_id'] ?? $tenantId;
        $validated['cabin_class'] = strtoupper($validated['cabin_class']);
        $validated['flight_number'] = strtoupper($validated['flight_number']);

        $inventory = FlightInventory::create($validated);
        $inventory->load(['airline', 'originAirport', 'destinationAirport', 'aircraft']);

        return ApiResponse::created($inventory, message: 'Flight inventory created successfully');
    }

    /**
     * Show flight inventory item.
     */
    public function show(int|string $id): JsonResponse
    {
        $inventory = is_numeric($id)
            ? FlightInventory::with(['airline', 'originAirport', 'destinationAirport', 'aircraft'])->findOrFail($id)
            : FlightInventory::with(['airline', 'originAirport', 'destinationAirport', 'aircraft'])->where('inventory_id', $id)->firstOrFail();

        return ApiResponse::success($inventory);
    }

    /**
     * Update flight inventory item.
     */
    public function update(Request $request, int|string $id): JsonResponse
    {
        $inventory = is_numeric($id)
            ? FlightInventory::findOrFail($id)
            : FlightInventory::where('inventory_id', $id)->firstOrFail();

        $validated = $request->validate([
            'airline_id' => ['sometimes', 'required', 'exists:flight_airlines,id'],
            'flight_number' => ['sometimes', 'required', 'string', 'max:20'],
            'origin_airport_id' => ['sometimes', 'required', 'exists:flight_airports,id'],
            'destination_airport_id' => ['sometimes', 'required', 'exists:flight_airports,id'],
            'departure_time' => ['sometimes', 'required', 'string'],
            'arrival_time' => ['sometimes', 'required', 'string'],
            'operating_days' => ['nullable', 'array'],
            'aircraft_id' => ['nullable', 'exists:flight_aircraft,id'],
            'cabin_class' => ['sometimes', 'required', 'string', Rule::exists('flight_cabin_classes', 'code')->where('is_active', true)],
            'total_seats' => ['sometimes', 'required', 'integer', 'min:0'],
            'available_seats' => ['sometimes', 'required', 'integer', 'min:0'],
            'baggage_policy' => ['nullable', 'array'],
            'seat_map_configuration' => ['nullable', 'array'],
            'is_refundable' => ['nullable', 'boolean'],
            'cancellation_policy' => ['nullable', 'string'],
            'reschedule_policy' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:active,inactive,suspended,cancelled'],
            'base_price' => ['sometimes', 'required', 'numeric', 'min:0'],
        ]);

        if (isset($validated['cabin_class'])) {
            $validated['cabin_class'] = strtoupper($validated['cabin_class']);
        }
        if (isset($validated['flight_number'])) {
            $validated['flight_number'] = strtoupper($validated['flight_number']);
        }

        $inventory->update($validated);
        $inventory->load(['airline', 'originAirport', 'destinationAirport', 'aircraft']);

        return ApiResponse::success($inventory->fresh(), 'Flight inventory updated successfully');
    }

    /**
     * Delete flight inventory item.
     */
    public function destroy(int|string $id): JsonResponse
    {
        if (str_starts_with((string) $id, 'flight:')) {
            $flightNum = strtoupper(substr((string) $id, 7));
            FlightInventory::where('flight_number', $flightNum)->delete();
            return ApiResponse::success(null, "All inventories for flight {$flightNum} deleted successfully");
        }

        $inventory = is_numeric($id)
            ? FlightInventory::findOrFail($id)
            : FlightInventory::where('inventory_id', $id)->firstOrFail();

        $inventory->delete();

        return ApiResponse::success(null, 'Flight inventory deleted successfully');
    }

    /**
     * Check seat availability and allocation.
     */
    public function availability(Request $request): JsonResponse
    {
        $request->validate([
            'flight_number' => ['nullable', 'string'],
            'origin_airport_id' => ['nullable', 'exists:flight_airports,id'],
            'destination_airport_id' => ['nullable', 'exists:flight_airports,id'],
            'cabin_class' => ['nullable', Rule::exists('flight_cabin_classes', 'code')->where('is_active', true)],
            'seats_needed' => ['nullable', 'integer', 'min:0'],
        ]);

        $query = FlightInventory::query()->active();

        if ($flightNumber = $request->query('flight_number')) {
            $query->where('flight_number', strtoupper($flightNumber));
        }

        if ($origin = $request->query('origin_airport_id')) {
            $query->where('origin_airport_id', $origin);
        }

        if ($destination = $request->query('destination_airport_id')) {
            $query->where('destination_airport_id', $destination);
        }

        if ($cabin = $request->query('cabin_class')) {
            $query->where('cabin_class', strtoupper($cabin));
        }

        if ($seatsNeeded = $request->integer('seats_needed', 1)) {
            $query->where('available_seats', '>=', $seatsNeeded);
        }

        $results = $query->with(['airline', 'originAirport', 'destinationAirport'])->get();

        return ApiResponse::success([
            'is_available' => $results->isNotEmpty(),
            'matching_inventories' => $results,
            'total_available_seats' => $results->sum('available_seats'),
        ]);
    }
}
