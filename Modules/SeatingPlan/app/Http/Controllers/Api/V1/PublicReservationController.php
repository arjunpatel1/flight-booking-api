<?php

namespace Modules\SeatingPlan\Http\Controllers\Api\V1;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cart\Support\PublicTenantGuard;
use Modules\Core\Http\Controllers\Controller;
use Modules\Menu\Models\OnlineMenu;
use Modules\SeatingPlan\Enums\ReservationStatus;
use Modules\SeatingPlan\Enums\ReservationType;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\TableReservation;
use Modules\Support\ApiResponse;
use Modules\User\Http\Concerns\ResolvesAppCustomer;

class PublicReservationController extends Controller
{
    use ResolvesAppCustomer;

    public function index(Request $request): JsonResponse
    {
        $customer = $this->customerForRequest($request);
        $reservations = TableReservation::query()
            ->withOutGlobalBranchPermission()
            ->with(['table:id,name', 'branch:id,name'])
            ->where(function ($query) use ($customer) {
                $query->where('customer_id', $customer->id)
                    ->when(filled($customer->phone), fn ($owned) => $owned->orWhere(function ($legacy) use ($customer) {
                        $legacy->whereNull('customer_id')
                            ->whereRaw("REPLACE(REPLACE(REPLACE(customer_phone, ' ', ''), '-', ''), '+', '') = ?", [
                                str_replace([' ', '-', '+'], '', (string) $customer->phone),
                            ]);
                    }));
            })
            ->whereHas('branch', fn ($query) => $query->where('tenant_id', $customer->tenant_id))
            ->latest('reservation_date')
            ->latest('reservation_time')
            ->limit(100)
            ->get()
            ->map(fn (TableReservation $reservation) => $this->payload($reservation));

        return ApiResponse::success(['reservations' => $reservations]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->guardCustomerReservations();

        $data = $request->validate([
            'menu_slug' => ['required', 'string', 'max:160'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['required', 'string', 'regex:/^[0-9+() -]{7,20}$/'],
            'customer_email' => ['nullable', 'email', 'max:190'],
            'guest_count' => ['required', 'integer', 'min:1', 'max:50'],
            'reservation_date' => ['required', 'date', 'after_or_equal:today'],
            'reservation_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:30', 'max:360'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
        ]);

        $customer = $request->user() ? $this->customerForRequest($request) : null;
        $branchId = $this->branchId($request, $data['menu_slug']);
        $start = Carbon::parse("{$data['reservation_date']} {$data['reservation_time']}");
        if ($start->isPast()) {
            throw ValidationException::withMessages([
                'reservation_time' => ['Choose a future reservation time.'],
            ]);
        }

        $duration = (int) ($data['duration_minutes'] ?? 90);
        $reservation = DB::transaction(function () use ($data, $branchId, $start, $duration, $customer) {
            $tables = Table::query()
                ->withOutGlobalBranchPermission()
                ->withoutGlobalActive()
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->where('capacity', '>=', $data['guest_count'])
                ->orderBy('capacity')
                ->lockForUpdate()
                ->get();

            $table = $tables->first(fn (Table $candidate) => !$this->hasConflict(
                $candidate->id,
                $start,
                $duration,
            ));

            if (!$table) {
                throw ValidationException::withMessages([
                    'reservation_time' => ['No table is available for this party and time. Try another time.'],
                ]);
            }

            return TableReservation::query()->create([
                'reference_no' => 'RSV-' . Str::upper(Str::random(10)),
                'booking_type' => ReservationType::Table->value,
                'branch_id' => $branchId,
                'customer_id' => $customer?->id,
                'table_id' => $table->id,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'customer_email' => $data['customer_email'] ?? null,
                'guest_count' => $data['guest_count'],
                'reservation_date' => $start->toDateString(),
                'reservation_time' => $start->format('H:i:s'),
                'duration_minutes' => $duration,
                'status' => ReservationStatus::Pending->value,
                'special_requests' => $data['special_requests'] ?? null,
            ]);
        });

        return ApiResponse::created(body: $this->payload($reservation->load(['table:id,name', 'branch:id,name'])));
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $this->guardCustomerReservations();

        return ApiResponse::success(body: $this->payload($this->find($request, $reference)));
    }

    public function cancel(Request $request, string $reference): JsonResponse
    {
        $this->guardCustomerReservations();

        $reservation = $this->find($request, $reference);
        abort_unless(in_array($reservation->status->value, [
            ReservationStatus::Pending->value,
            ReservationStatus::Confirmed->value,
        ], true), 422, 'This reservation can no longer be cancelled online.');

        $reservation->update([
            'status' => ReservationStatus::Cancelled->value,
            'cancel_reason' => 'Cancelled by customer',
            'cancelled_at' => now(),
        ]);

        return ApiResponse::success(body: $this->payload($reservation->fresh(['table:id,name', 'branch:id,name'])));
    }

    public function update(Request $request, string $reference): JsonResponse
    {
        $this->guardCustomerReservations();
        $reservation = $this->find($request, $reference);
        abort_unless(in_array($reservation->status->value, [
            ReservationStatus::Pending->value,
            ReservationStatus::Confirmed->value,
        ], true), 422, 'This reservation can no longer be modified online.');

        $data = $request->validate([
            'guest_count' => ['required', 'integer', 'min:1', 'max:50'],
            'reservation_date' => ['required', 'date', 'after_or_equal:today'],
            'reservation_time' => ['required', 'date_format:H:i'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
        ]);
        $start = Carbon::parse("{$data['reservation_date']} {$data['reservation_time']}");
        if ($start->isPast()) {
            throw ValidationException::withMessages(['reservation_time' => ['Choose a future reservation time.']]);
        }

        $table = Table::query()
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->where('branch_id', $reservation->branch_id)
            ->where('is_active', true)
            ->where('capacity', '>=', $data['guest_count'])
            ->orderBy('capacity')
            ->get()
            ->first(fn (Table $candidate) => !$this->hasConflict(
                $candidate->id,
                $start,
                (int) $reservation->duration_minutes,
                $reservation->id,
            ));
        if (!$table) {
            throw ValidationException::withMessages(['reservation_time' => ['No table is available for this party and time. Try another time.']]);
        }

        $reservation->update([
            'table_id' => $table->id,
            'guest_count' => $data['guest_count'],
            'reservation_date' => $start->toDateString(),
            'reservation_time' => $start->format('H:i:s'),
            'special_requests' => $data['special_requests'] ?? null,
            'status' => ReservationStatus::Pending->value,
            'confirmed_at' => null,
        ]);

        return ApiResponse::success(body: $this->payload($reservation->fresh(['table:id,name', 'branch:id,name'])));
    }

    private function guardCustomerReservations(): void
    {
        abort_unless((bool) setting('customer_app_enabled', true), 403, 'Customer ordering is not available for this restaurant.');
        abort_unless((bool) setting('customer_app_reservations_enabled', true), 403, 'Online reservations are not available for this restaurant.');
    }

    private function find(Request $request, string $reference): TableReservation
    {
        $query = TableReservation::query()
            ->withOutGlobalBranchPermission()
            ->with(['table:id,name', 'branch:id,name'])
            ->whereHas('branch', fn ($query) => $query->where('tenant_id', PublicTenantGuard::tenantId($request)))
            ->where('reference_no', $reference);

        if ($request->user()) {
            $customer = $this->customerForRequest($request);
            $query->where(function ($owned) use ($customer) {
                $owned->where('customer_id', $customer->id)
                    ->when(filled($customer->phone), fn ($scoped) => $scoped->orWhere(function ($legacy) use ($customer) {
                        $legacy->whereNull('customer_id')
                            ->whereRaw("REPLACE(REPLACE(REPLACE(customer_phone, ' ', ''), '-', ''), '+', '') = ?", [
                                str_replace([' ', '-', '+'], '', (string) $customer->phone),
                            ]);
                    }));
            });
        } else {
            $phone = (string) $request->validate(['phone' => ['required', 'string', 'max:20']])['phone'];
            $query->whereRaw("REPLACE(REPLACE(REPLACE(customer_phone, ' ', ''), '-', ''), '+', '') = ?", [
                str_replace([' ', '-', '+'], '', $phone),
            ]);
        }

        return $query->firstOrFail();
    }

    private function branchId(Request $request, string $slug): int
    {
        $branchId = OnlineMenu::query()
            ->withOutGlobalBranchPermission()
            ->whereHas('branch', fn ($query) => $query->where('tenant_id', PublicTenantGuard::tenantId($request)))
            ->where('slug', $slug)
            ->where('is_active', true)
            ->value('branch_id');

        abort_if(blank($branchId), 404, 'The restaurant reservation menu is unavailable.');

        return (int) $branchId;
    }

    private function hasConflict(int $tableId, Carbon $start, int $duration, ?int $exceptReservationId = null): bool
    {
        $end = $start->copy()->addMinutes($duration);

        return TableReservation::query()
            ->withOutGlobalBranchPermission()
            ->where('table_id', $tableId)
            ->when($exceptReservationId, fn ($query) => $query->where('id', '!=', $exceptReservationId))
            ->whereDate('reservation_date', $start->toDateString())
            ->whereIn('status', [ReservationStatus::Pending->value, ReservationStatus::Confirmed->value, ReservationStatus::Seated->value])
            ->whereRaw('TIMESTAMP(reservation_date, reservation_time) < ?', [$end])
            ->whereRaw('DATE_ADD(TIMESTAMP(reservation_date, reservation_time), INTERVAL duration_minutes MINUTE) > ?', [$start])
            ->exists();
    }

    private function payload(TableReservation $reservation): array
    {
        return [
            'reference' => $reservation->reference_no,
            'status' => $reservation->status->value,
            'customer_name' => $reservation->customer_name,
            'guest_count' => $reservation->guest_count,
            'date' => $reservation->reservation_date?->format('Y-m-d'),
            'time' => substr((string) $reservation->reservation_time, 0, 5),
            'duration_minutes' => $reservation->duration_minutes,
            'table' => $reservation->table?->name,
            'branch_name' => $reservation->branch?->name,
            'special_requests' => $reservation->special_requests,
        ];
    }
}
