<?php

namespace Modules\SeatingPlan\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Events\PlatformEvent;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Table;

class TableUpdateStatus implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    /**
     * Create a new event instance.
     *
     * @param Table $table
     * @param int|null $changedById
     * @param TableStatus $status
     * @param string|null $note
     */
    public function __construct(
        public Table       $table,
        public TableStatus $status,
        public ?int        $changedById = null,
        public ?string     $note = null,
    )
    {
    }

    public function broadcastOn(): array
    {
        $branchId = $this->table->branch_id;

        return [
            new PrivateChannel("pos.tables.branch.{$branchId}"),
            new PrivateChannel("branch.{$branchId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return PlatformEvent::TABLE_STATUS_UPDATED;
    }

    public function broadcastWith(): array
    {
        $table = [
            'id' => $this->table->id,
            'name' => $this->table->name,
            'branch_id' => $this->table->branch_id,
            'status' => $this->status->value,
            'current_status' => $this->status->value,
            'changed_by_id' => $this->changedById,
            'updated_at' => $this->table->updated_at?->toISOString(),
        ];

        return [
            ...PlatformEvent::envelope(
                eventName: $this->broadcastAs(),
                entity: 'table',
                entityId: $this->table->id,
                branchId: $this->table->branch_id,
                payload: ['table' => $table],
            ),
            'table' => $table,
            'branch_id' => $this->table->branch_id,
            'table_id' => $this->table->id,
            'status' => $this->status->value,
        ];
    }
}
