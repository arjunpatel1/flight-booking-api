<?php

namespace Modules\Voice\Listeners;

use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Events\TableUpdateStatus;
use Modules\Voice\Services\VoiceAnnouncementService;

class TableStatusVoiceListener
{
    public function __construct(
        private VoiceAnnouncementService $voiceService
    ) {
    }

    public function handle(TableUpdateStatus $event): void
    {
        if ($event->status !== TableStatus::Available || !$event->table->branch_id) {
            return;
        }

        $event->table->loadMissing(['floor', 'zone', 'waiter']);

        $this->voiceService->triggerAnnouncement(
            (int) $event->table->branch_id,
            null,
            'TableReady',
            [
                'TableId' => $event->table->id,
                'TableNumber' => $event->table->name,
                'FloorName' => $event->table->floor?->name ?? '',
                'ZoneName' => $event->table->zone?->name ?? '',
                'WaiterName' => $event->table->waiter?->name ?? '',
            ]
        );
    }
}
