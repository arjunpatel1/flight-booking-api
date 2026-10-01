<?php

namespace Modules\SeatingPlan\Services\Reservation;

use Illuminate\Support\Collection;
use Modules\SeatingPlan\Models\TableReservation;

interface ReservationServiceInterface
{
    public function get(array $filters = [], array $sorts = []): Collection;

    public function upcoming(?int $branchId = null): Collection;

    public function byTable(int $tableId, ?string $date = null): Collection;

    public function store(array $data): TableReservation;

    public function update(int $id, array $data): TableReservation;

    public function confirm(int $id): TableReservation;

    public function seat(int $id): TableReservation;

    public function cancel(int $id, ?string $reason = null): TableReservation;

    public function meta(?int $branchId = null): array;
}
