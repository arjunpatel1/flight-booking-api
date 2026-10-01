<?php

namespace Tests\Unit;

use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Transformers\Api\V1\TableViewerResource;
use ReflectionMethod;
use Tests\TestCase;

class TableViewerResourceStatusTest extends TestCase
{
    public function test_stale_occupied_table_without_active_orders_displays_available(): void
    {
        $this->assertSame(
            TableStatus::Available,
            $this->resolveStatus(TableStatus::Occupied, activeOrderCount: 0, activeGuestCount: 0)
        );
    }

    public function test_table_with_active_orders_displays_occupied(): void
    {
        $this->assertSame(
            TableStatus::Occupied,
            $this->resolveStatus(TableStatus::Available, activeOrderCount: 1, activeGuestCount: 2)
        );
    }

    public function test_manual_non_order_status_is_preserved_when_no_active_orders(): void
    {
        $this->assertSame(
            TableStatus::Reserved,
            $this->resolveStatus(TableStatus::Reserved, activeOrderCount: 0, activeGuestCount: 0)
        );
    }

    public function test_merged_status_is_preserved(): void
    {
        $this->assertSame(
            TableStatus::Merged,
            $this->resolveStatus(TableStatus::Merged, activeOrderCount: 1, activeGuestCount: 2)
        );
    }

    private function resolveStatus(TableStatus $storedStatus, int $activeOrderCount, int $activeGuestCount): TableStatus
    {
        $table = new Table();
        $table->status = $storedStatus;

        $resource = new TableViewerResource($table);
        $method = new ReflectionMethod($resource, 'resolveViewerStatus');
        $method->setAccessible(true);

        return $method->invoke($resource, $activeOrderCount, $activeGuestCount);
    }
}
