<?php

namespace Modules\SeatingPlan\Services\Reservation;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Branch\Models\Branch;
use Modules\SeatingPlan\Enums\ReservationStatus;
use Modules\SeatingPlan\Enums\ReservationType;
use Modules\SeatingPlan\Models\Floor;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\TableReservation;
use Modules\SeatingPlan\Models\Zone;

class ReservationService implements ReservationServiceInterface
{
    public function get(array $filters = [], array $sorts = []): Collection
    {
        return $this->baseQuery($filters)
            ->orderBy('reservation_date')
            ->orderBy('reservation_time')
            ->limit(500)
            ->get();
    }

    public function upcoming(?int $branchId = null): Collection
    {
        $filters = [
            'branch_id' => $branchId,
            'from' => today()->format('Y-m-d'),
        ];

        return $this->baseQuery($filters)
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed])
            ->orderBy('reservation_date')
            ->orderBy('reservation_time')
            ->limit(100)
            ->get();
    }

    public function byTable(int $tableId, ?string $date = null): Collection
    {
        $table = Table::query()->findOrFail($tableId);
        $this->abortIfBranchMismatch($table->branch_id);

        return $this->baseQuery([
            'table_id' => $tableId,
            'date' => $date ?: today()->format('Y-m-d'),
        ])
            ->orderBy('reservation_time')
            ->get();
    }

    public function store(array $data): TableReservation
    {
        $data = $this->prepareData($data);
        $this->assertNoConflict($data);

        return TableReservation::query()->create([
            ...$data,
            'reference_no' => $this->referenceNo(),
            'status' => ReservationStatus::Pending,
        ])->load(['branch:id,name', 'table:id,name,floor_id,zone_id']);
    }

    public function update(int $id, array $data): TableReservation
    {
        $reservation = $this->findOrFail($id);
        $data = $this->prepareData($data, $reservation);
        $this->assertNoConflict($data, $reservation->id);
        $reservation->update($data);

        return $reservation->refresh()->load(['branch:id,name', 'table:id,name,floor_id,zone_id']);
    }

    public function confirm(int $id): TableReservation
    {
        $reservation = $this->findOrFail($id);
        $reservation->update([
            'status' => ReservationStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        return $reservation->refresh()->load(['branch:id,name', 'table:id,name,floor_id,zone_id']);
    }

    public function seat(int $id): TableReservation
    {
        $reservation = $this->findOrFail($id);
        $reservation->update([
            'status' => ReservationStatus::Seated,
            'seated_at' => now(),
        ]);

        return $reservation->refresh()->load(['branch:id,name', 'table:id,name,floor_id,zone_id']);
    }

    public function cancel(int $id, ?string $reason = null): TableReservation
    {
        $reservation = $this->findOrFail($id);
        $reservation->update([
            'status' => ReservationStatus::Cancelled,
            'cancel_reason' => $reason,
            'cancelled_at' => now(),
        ]);

        return $reservation->refresh()->load(['branch:id,name', 'table:id,name,floor_id,zone_id']);
    }

    public function meta(?int $branchId = null): array
    {
        $branchId = $this->effectiveBranchId($branchId);
        $tables = Table::query()
            ->with(['floor:id,name,branch_id', 'zone:id,name,branch_id'])
            ->when(!is_null($branchId), fn($query) => $query->whereBranch($branchId))
            ->orderBy('order')
            ->get(['id', 'name', 'branch_id', 'floor_id', 'zone_id', 'capacity']);

        return [
            'branches' => is_null($branchId)
                ? Branch::list()
                : Branch::query()->select(['id', 'name'])->where('id', $branchId)->get(),
            'floors' => Floor::query()
                ->when(!is_null($branchId), fn($query) => $query->whereBranch($branchId))
                ->orderBy('order')
                ->get(['id', 'name', 'branch_id']),
            'zones' => Zone::query()
                ->when(!is_null($branchId), fn($query) => $query->whereBranch($branchId))
                ->orderBy('order')
                ->get(['id', 'name', 'branch_id', 'floor_id']),
            'tables' => $tables
                ->map(fn(Table $table) => [
                    'id' => $table->id,
                    'name' => $table->name,
                    'branch_id' => $table->branch_id,
                    'floor_id' => $table->floor_id,
                    'zone_id' => $table->zone_id,
                    'capacity' => $table->capacity,
                    'floor' => $table->floor?->name,
                    'zone' => $table->zone?->name,
                ]),
            'statuses' => collect(ReservationStatus::cases())
                ->map(fn(ReservationStatus $status) => [
                    'id' => $status->value,
                    'name' => $status->trans(),
                    'color' => $status->color(),
                ])
                ->values(),
            'booking_types' => ReservationType::toArrayTrans(),
            'time_slots' => $this->timeSlots(),
        ];
    }

    private function baseQuery(array $filters = []): Builder
    {
        $branchId = $this->effectiveBranchId($filters['branch_id'] ?? null);

        return TableReservation::query()
            ->with(['branch:id,name', 'table:id,name,floor_id,zone_id'])
            ->when(!is_null($branchId), fn($query) => $query->where('branch_id', $branchId))
            ->when(!empty($filters['table_id']), function ($query) use ($filters) {
                $tableId = (int) $filters['table_id'];

                $query->where(function (Builder $query) use ($tableId) {
                    $query->where('table_id', $tableId)
                        ->orWhereJsonContains('table_ids', $tableId);
                });
            })
            ->when(!empty($filters['date']), fn($query) => $query->whereDate('reservation_date', $filters['date']))
            ->when(!empty($filters['status']), fn($query) => $query->where('status', $filters['status']))
            ->when(!empty($filters['booking_type']), fn($query) => $query->where('booking_type', $filters['booking_type']))
            ->when(!empty($filters['guest_count']), fn($query) => $query->where('guest_count', $filters['guest_count']))
            ->when(!empty($filters['min_guest_count']), fn($query) => $query->where('guest_count', '>=', $filters['min_guest_count']))
            ->when(!empty($filters['max_guest_count']), fn($query) => $query->where('guest_count', '<=', $filters['max_guest_count']))
            ->when(!empty($filters['search']), fn($query) => $query->search($filters['search']));
    }

    private function prepareData(array $data, ?TableReservation $reservation = null): array
    {
        $bookingType = ReservationType::tryFrom($data['booking_type'] ?? $reservation?->booking_type?->value ?? ReservationType::Table->value)
            ?: ReservationType::Table;

        $tables = collect();
        if ($bookingType === ReservationType::Table) {
            $tableIds = $this->normalizeTableIds($data, $reservation);
            $tables = Table::query()
                ->whereIn('id', $tableIds)
                ->get();

            abort_if($tables->count() !== count($tableIds), 422, __('seatingplan::reservations.table_required'));

            $branchIds = $tables->pluck('branch_id')->unique()->values();
            abort_if($branchIds->count() !== 1, 422, __('seatingplan::reservations.tables_same_branch_required'));

            $this->abortIfBranchMismatch((int) $branchIds->first());
        }

        $primaryTable = $tables->first();
        $branchId = $this->effectiveBranchId($data['branch_id'] ?? $reservation?->branch_id ?? $primaryTable?->branch_id);
        abort_if(is_null($branchId), 422, __('seatingplan::reservations.branch_required'));
        $this->abortIfBranchMismatch($branchId);

        $tableIds = $bookingType === ReservationType::Table
            ? $tables->pluck('id')->map(fn($id) => (int) $id)->values()->all()
            : null;

        return [
            'booking_type' => $bookingType,
            'branch_id' => $branchId,
            'table_id' => $bookingType === ReservationType::Table ? $primaryTable?->id : null,
            'table_ids' => $tableIds,
            'hall_name' => $data['hall_name'] ?? null,
            'event_title' => $data['event_title'] ?? null,
            'customer_name' => $data['customer_name'],
            'customer_phone' => $data['customer_phone'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
            'guest_count' => $data['guest_count'],
            'reservation_date' => $data['reservation_date'],
            'reservation_time' => $data['reservation_time'],
            'duration_minutes' => $data['duration_minutes'] ?? 60,
            'deposit_amount' => $data['deposit_amount'] ?? 0,
            'special_requests' => $data['special_requests'] ?? null,
        ];
    }

    private function assertNoConflict(array $data, ?int $ignoreId = null): void
    {
        if (($data['booking_type'] instanceof ReservationType ? $data['booking_type'] : ReservationType::from($data['booking_type'])) !== ReservationType::Table) {
            return;
        }

        $start = $this->minutes($data['reservation_time']);
        $end = $start + (int) $data['duration_minutes'];

        $tableIds = $data['table_ids'] ?? [$data['table_id']];

        $conflict = TableReservation::query()
            ->where(function (Builder $query) use ($tableIds) {
                $query->whereIn('table_id', $tableIds);

                foreach ($tableIds as $tableId) {
                    $query->orWhereJsonContains('table_ids', (int) $tableId);
                }
            })
            ->whereDate('reservation_date', $data['reservation_date'])
            ->whereIn('status', [ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::Seated])
            ->when($ignoreId, fn($query) => $query->whereKeyNot($ignoreId))
            ->get()
            ->contains(function (TableReservation $reservation) use ($start, $end) {
                $reservationStart = $this->minutes($reservation->reservation_time);
                $reservationEnd = $reservationStart + $reservation->duration_minutes;

                return $start < $reservationEnd && $end > $reservationStart;
            });

        abort_if($conflict, 422, __('seatingplan::reservations.table_already_reserved'));
    }

    private function normalizeTableIds(array $data, ?TableReservation $reservation = null): array
    {
        $ids = $data['table_ids'] ?? null;

        if (empty($ids) && !empty($data['table_id'])) {
            $ids = [$data['table_id']];
        }

        if (empty($ids) && $reservation) {
            $ids = $reservation->table_ids ?: [$reservation->table_id];
        }

        $ids = collect($ids)
            ->filter()
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        abort_if(empty($ids), 422, __('seatingplan::reservations.table_required'));

        return $ids;
    }

    private function findOrFail(int $id): TableReservation
    {
        $reservation = TableReservation::query()->findOrFail($id);
        $this->abortIfBranchMismatch($reservation->branch_id);

        return $reservation;
    }

    private function effectiveBranchId(?int $branchId = null): ?int
    {
        $user = auth()->user();

        return $user?->assignedToBranch() ? $user->branch_id : $branchId;
    }

    private function abortIfBranchMismatch(?int $branchId): void
    {
        $user = auth()->user();
        abort_if($user?->assignedToBranch() && (int) $branchId !== (int) $user->branch_id, 403);
    }

    private function referenceNo(): string
    {
        do {
            $reference = 'RSV-' . strtoupper(str()->random(10));
        } while (TableReservation::query()->where('reference_no', $reference)->exists());

        return $reference;
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', substr($time, 0, 5)));

        return ($hours * 60) + $minutes;
    }

    private function timeSlots(): array
    {
        $slots = [];
        for ($hour = 8; $hour <= 23; $hour++) {
            foreach ([0, 30] as $minute) {
                $slots[] = sprintf('%02d:%02d', $hour, $minute);
            }
        }

        return $slots;
    }
}
