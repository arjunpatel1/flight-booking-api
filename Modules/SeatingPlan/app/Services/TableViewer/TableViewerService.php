<?php

namespace Modules\SeatingPlan\Services\TableViewer;

use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Branch\Models\Branch;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderDiscount;
use Modules\Order\Models\OrderProduct;
use Modules\Order\Models\OrderTax;
use Modules\Order\Services\SaveOrder\SaveOrderServiceInterface;
use Modules\Payment\Models\Payment;
use Modules\Pos\Enums\PosSubmitAction;
use Modules\SeatingPlan\Enums\TableMergeType;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Events\TableAssignWaiter;
use Modules\SeatingPlan\Models\Floor;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\TableMerge;
use Modules\SeatingPlan\Models\Zone;
use Modules\SeatingPlan\Services\Table\TableServiceInterface;
use Modules\User\Enums\DefaultRole;

class TableViewerService implements TableViewerServiceInterface
{
    /**
     * Create a new instance of TableViewerService
     */
    public function __construct(protected TableServiceInterface $service) {}

    /** {@inheritDoc} */
    public function show(int $id): Table
    {
        return $this->service->getModel()
            ->with([
                'branch:id,name',
                'waiter:id,name',
                'floor:id,name,layout_width,layout_height,show_grid,show_guide_lines,show_zone_labels,show_table_labels,compact_tables,zone_layouts,layout_elements',
                'zone:id,name',
                'currentMerge' => fn ($query) => $query->with(['members' => fn ($query) => $query->with('table:id,name')]),
                'activeOrder' => fn ($query) => $query->with([
                    'products' => fn ($query) => $query
                        ->whereNotIn('status', [OrderProductStatus::Cancelled, OrderProductStatus::Refunded])
                        ->without('taxes', 'options'),
                    'customer:id,name',
                    'table:id,name',
                    'tableMerge:id,type',
                ]),
            ])
            ->findOrFail($id);
    }

    /** {@inheritDoc} */
    public function findOrFail(int $id): Builder|array|EloquentCollection|Table
    {
        return $this->service->getModel()->query()->findOrFail($id);
    }

    /** {@inheritDoc} */
    public function assignWaiter(int $id, array $data): void
    {
        $table = $this->service->findOrFail($id);
        $oldWaiterId = $table->assigned_waiter_id;
        $waiterId = $data['waiter_id'] ?? null;

        $table->update(['assigned_waiter_id' => $waiterId]);

        if (! is_null($table->activeOrder)) {
            $table->activeOrder->update(['waiter_id' => $waiterId]);
        }

        event(new TableAssignWaiter(
            table: $table,
            waiterId: ($data['waiter_id'] ?? null),
            oldWaiterId: $oldWaiterId
        ));
    }

    /** {@inheritDoc} */
    public function merge(int $id, array $data): void
    {
        DB::transaction(function () use ($id, $data) {
            $user = auth()->user();
            $branch = $user->assignedToBranch() ? $user->branch : Branch::find($data['branch_id']);
            $allTables = $this->service
                ->getModel()
                ->query()
                ->whereIn('id', [$id, ...$data['table_ids']])
                ->where('branch_id', $branch->id)
                ->availableMerge()
                ->lockForUpdate()
                ->get();

            $mainTable = $allTables->first(fn (Table $table) => $table->id === $id);

            abort_if($mainTable->current_merge_id, 400, __('seatingplan::messages.main_table_is_already_merged'));

            $type = TableMergeType::from($data['type']);
            $tables = $allTables->filter(fn (Table $table) => $table->id !== $id);
            $tableIds = $tables->pluck('id')->toArray();
            $allTableIds = [$mainTable->id, ...$tableIds];

            /** @var TableMerge $merge */
            $merge = $mainTable
                ->merges()
                ->create(['branch_id' => $branch->id, 'type' => $type]);

            $allTables->each(fn (Table $table) => $merge->members()
                ->create([
                    'table_id' => $table->id,
                    'is_main' => $table->id == $mainTable->id,
                ]));
            switch ($type) {
                case TableMergeType::Capacity:
                    $this->service->getModel()->query()
                        ->whereIn('id', $allTableIds)
                        ->update(['current_merge_id' => $merge->id]);
                    break;

                case TableMergeType::Billing:
                    $this->service->getModel()->query()
                        ->whereIn('id', $allTableIds)
                        ->update(['current_merge_id' => $merge->id]);

                    Order::query()
                        ->forMerge()
                        ->whereIn('table_id', $allTableIds)
                        ->update(['table_merge_id' => $merge->id]);
                    break;

                case TableMergeType::Order:
                    $this->service->getModel()->query()
                        ->whereIn('id', $tableIds)
                        ->update([
                            'current_merge_id' => $merge->id,
                            'status' => TableStatus::Merged,
                        ]);

                    $tables->each(fn (Table $t) => $t->storeStatusLog(status: TableStatus::Merged));

                    if ($mainTable->status === TableStatus::Occupied) {
                        $mainTable->update(['current_merge_id' => $merge->id]);
                    } else {
                        $hasOrders = Order::query()
                            ->whereIn('table_id', $allTableIds)
                            ->forMerge()
                            ->exists();

                        if ($hasOrders) {
                            $mainTable->update([
                                'status' => TableStatus::Occupied,
                                'current_merge_id' => $merge->id,
                            ]);
                            $mainTable->storeStatusLog(status: TableStatus::Occupied);
                        } else {
                            $mainTable->update(['current_merge_id' => $merge->id]);
                        }
                    }

                    /** @var Order $mainOrder */
                    $mainOrder = Order::query()
                        ->where('table_id', $mainTable->id)
                        ->forMerge()
                        ->first();

                    $otherOrders = Order::query()
                        ->where('id', '!=', $mainOrder?->id)
                        ->whereIn('table_id', $tableIds)
                        ->forMerge()
                        ->get();

                    $otherOrderIds = $otherOrders->pluck('id')->toArray();

                    if (! empty($otherOrderIds)) {
                        $notes = [];
                        if (! is_null($mainOrder) && $mainOrder->notes) {
                            $notes = [$mainOrder->notes];
                        }
                        $notes = [...$notes, ...$otherOrders->pluck('notes')->toArray()];
                        $notes = implode("\n----------------\n", $notes) ?: null;

                        if (is_null($mainOrder)) {
                            $mainOrder = app(SaveOrderServiceInterface::class)
                                ->create([
                                    'branch_id' => $data['branch_id'],
                                    'type' => OrderType::DineIn->value,
                                    'table_id' => $mainTable->id,
                                    'register_id' => $data['register_id'],
                                    'session_id' => $data['session_id'],
                                    'submit_action' => PosSubmitAction::HoldOrder->value,
                                    'guest_count' => $otherOrders->sum('guest_count'),
                                    'notes' => $notes,
                                ]);
                        } else {
                            $mainOrder->update([
                                'guest_count' => $mainOrder->guest_count + $otherOrders->sum('guest_count'),
                                'notes' => $notes,
                            ]);
                        }

                        if (is_null($mainOrder->discount)) {
                            OrderDiscount::query()
                                ->whereIn('order_id', $otherOrderIds)
                                ->first()
                                ?->update(['order_id' => $mainOrder->id]);
                        }

                        $deletedDiscounts = OrderDiscount::query()
                            ->whereIn('order_id', $otherOrderIds)
                            ->delete();

                        OrderProduct::query()
                            ->whereIn('order_id', $otherOrderIds)
                            ->update(['order_id' => $mainOrder->id]);

                        Payment::query()
                            ->whereIn('order_id', $otherOrderIds)
                            ->update(['order_id' => $mainOrder->id]);

                        OrderTax::query()
                            ->whereIn('order_id', $otherOrderIds)
                            ->update(['order_id' => $mainOrder->id]);

                        Order::query()
                            ->whereIn('id', $otherOrderIds)
                            ->update([
                                'status' => OrderStatus::Merged,
                                'merged_into_order_id' => $mainOrder->id,
                                'merged_by' => $user->id,
                                'merged_at' => now(),
                            ]);

                        $orderStatusNote = ($deletedDiscounts > 0) ? 'ORDER_DISCOUNT_REMOVED_ON_MERGE' : 'ORDER_MERGED';

                        /** @var Order $otherOrder */
                        foreach ($otherOrders as $otherOrder) {
                            $otherOrder->storeStatusLog(
                                status: OrderStatus::Merged,
                                changedById: $user->id,
                                note: "$orderStatusNote :$mainOrder->reference_no"
                            );
                        }

                        $mainOrder->recalculate(deleteTaxesDuplicates: true);
                    }
                    break;
            }
        });
    }

    /** {@inheritDoc} */
    public function transfer(int $id, array $data): void
    {
        DB::transaction(function () use ($id, $data) {
            $user = auth()->user();
            $sourceTable = $this->service
                ->getModel()
                ->query()
                ->with('activeOrders')
                ->when($user->assignedToBranch(), fn ($query) => $query->where('branch_id', $user->branch_id))
                ->lockForUpdate()
                ->findOrFail($id);

            $targetTable = $this->service
                ->getModel()
                ->query()
                ->with('activeOrders')
                ->where('branch_id', $sourceTable->branch_id)
                ->lockForUpdate()
                ->findOrFail($data['target_table_id']);

            abort_if(
                $sourceTable->current_merge_id,
                400,
                __('seatingplan::messages.transfer_failed_source_merged')
            );

            abort_if(
                $targetTable->current_merge_id,
                400,
                __('seatingplan::messages.transfer_failed_target_merged')
            );

            abort_if(
                $sourceTable->activeOrders->isEmpty(),
                400,
                __('seatingplan::messages.transfer_failed_no_active_orders')
            );

            $targetCanReceiveOrder = in_array(
                $targetTable->status,
                [TableStatus::Available, TableStatus::Occupied],
                true
            ) && $targetTable->activeOrders->isEmpty();

            abort_unless(
                $targetCanReceiveOrder,
                400,
                __('seatingplan::messages.transfer_failed_target_not_available')
            );

            Order::query()
                ->where('table_id', $sourceTable->id)
                ->activeOrders()
                ->update([
                    'table_id' => $targetTable->id,
                    'table_merge_id' => null,
                ]);

            $targetTable->update(['status' => TableStatus::Occupied]);
            $targetTable->storeStatusLog(
                status: TableStatus::Occupied,
                changedById: $user->id,
                note: "TABLE_TRANSFER_FROM:{$sourceTable->id}"
            );

            $sourceTable->update(['status' => TableStatus::Available]);
            $sourceTable->storeStatusLog(
                status: TableStatus::Available,
                changedById: $user->id,
                note: "TABLE_TRANSFER_TO:{$targetTable->id}"
            );
        });
    }

    /** {@inheritDoc} */
    public function get(?int $branchId = null): array
    {
        $user = auth()->user();
        $assignedBranchId = $user->assignedToBranch() ? $user->branch_id : null;
        $branchId = $assignedBranchId ?? $branchId;

        $tables = $this->service->getModel()
            ->query()
            ->whereHas('floor', fn ($query) => $query->where('is_active', true))
            ->whereHas('zone', fn ($query) => $query->where('is_active', true))
            ->with([
                'branch:id,name',
                'floor:id,name,layout_width,layout_height,show_grid,show_guide_lines,show_zone_labels,show_table_labels,compact_tables,zone_layouts,layout_elements',
                'zone:id,name,floor_id',
                'activeOrders:id,table_id,waiter_id,created_by,type,status,payment_status,total,currency,currency_rate,order_number,reference_no,guest_count,created_at,updated_at,scheduled_at',
                'activeOrders.products:id,order_id,product_id,status,quantity',
                'activeOrders.waiter:id,name',
            ])
            ->when(! is_null($branchId), fn ($query) => $query->where('branch_id', $branchId))
            ->orderby('order')
            ->get();

        return [
            'tables' => $tables,
            'floors' => Floor::list($branchId),
            'zones' => Zone::list($branchId),
            'statuses' => TableStatus::toArrayTrans(),
            'branches' => is_null($assignedBranchId)
                ? Branch::list()
                : Branch::query()
                    ->select(['id', 'name', 'currency'])
                    ->where('id', $assignedBranchId)
                    ->get(),
        ];
    }

    /** {@inheritDoc} */
    public function savePositions(array $positions): void
    {
        DB::transaction(function () use ($positions) {
            $user = auth()->user();
            $positionsByTable = collect($positions)->keyBy('id');
            $tableIds = $positionsByTable->keys()->all();

            $tables = $this->service
                ->getModel()
                ->query()
                ->whereIn('id', $tableIds)
                ->when($user->assignedToBranch(), fn ($query) => $query->where('branch_id', $user->branch_id))
                ->lockForUpdate()
                ->get();

            abort_if(
                $tables->count() !== count($tableIds),
                422,
                __('seatingplan::messages.invalid_table_layout_positions')
            );

            $tables->each(function (Table $table) use ($positionsByTable) {
                $position = $positionsByTable->get($table->id);

                $table->update([
                    'pos_x' => $position['pos_x'],
                    'pos_y' => $position['pos_y'],
                    'rotation' => $position['rotation'],
                    'scale' => $position['scale'],
                ]);
            });
        });
    }

    /** {@inheritDoc} */
    public function makeAsAvailable(int $id): void
    {
        abort_if(
            ! (bool) setting('waiter_table_status_flow_enabled', true)
            && auth()->user()?->hasRole(DefaultRole::Waiter->value),
            403,
            __('admin::messages.action_unauthorized')
        );

        $table = $this->service->findOrFail($id);

        abort_if(
            $table->status !== TableStatus::Cleaning,
            400,
            __('seatingplan::messages.fail_make_table_as_available', ['status' => $table->status->trans()])
        );

        $table->update(['status' => TableStatus::Available]);
        $table->storeStatusLog(status: TableStatus::Available, changedById: auth()->id());
    }

    /** {@inheritDoc} */
    public function getMergeMeta(int $id): array
    {
        $table = $this->service->findOrFail($id);

        return [
            'tables' => $this->service
                ->getModel()
                ->query()
                ->with(['floor:id,name', 'zone:id,name'])
                ->whereNot('id', $id)
                ->orderBy('order')
                ->orderBy('floor_id')
                ->orderBy('zone_id')
                ->where('branch_id', $table->branch_id)
                ->availableMerge()
                ->get()
                ->map(fn (Table $table) => [
                    'id' => $table->id,
                    'name' => "$table->name  ({$table->floor->name} • {$table->zone->name})",
                ]),
            'types' => TableMergeType::toArrayTrans(),
        ];
    }

    /** {@inheritDoc} */
    public function splitTable(int $tableId): void
    {
        DB::transaction(function () use ($tableId) {
            $user = auth()->user();

            // The /tables/viewer/{id}/split route passes a TABLE id, not a merge
            // id. Resolve the merge the table currently belongs to — previously
            // the table id was used directly as a merge id, so unmerge either
            // 404'd or split the wrong group.
            $table = $this->service
                ->getModel()
                ->query()
                ->when(
                    $user->assignedToBranch(),
                    fn ($query) => $query->where('branch_id', $user->branch_id)
                )
                ->findOrFail($tableId);

            abort_if(
                $table->current_merge_id === null,
                400,
                __('seatingplan::messages.split_failed_not_merged')
            );

            $merge = TableMerge::query()
                ->where('id', $table->current_merge_id)
                ->with(['members.table'])
                ->lockForUpdate()
                ->firstOrFail();

            $tables = $merge->members->pluck('table');
            $tableIds = $merge->members->pluck('table_id')->toArray();
            $main = $merge->members->firstWhere('is_main', true)?->table;

            abort_if(! $main, 400, __('seatingplan::messages.main_table_missing'));

            $activeOrders = Order::query()
                ->whereIn('table_id', $tableIds)
                ->activeOrders()
                ->latest()
                ->get();

            abort_if($activeOrders->count() > 0, 400, __('seatingplan::messages.split_failed_active_orders'));

            $this->service
                ->getModel()
                ->whereIn('id', $tableIds)
                ->update([
                    'current_merge_id' => null,
                    'status' => TableStatus::Available,
                ]);

            $tables->each(fn (Table $table) => $table->storeStatusLog(status: TableStatus::Available));

            $merge->update(['closed_at' => now(), 'closed_by' => $user->id]);
        });
    }
}
