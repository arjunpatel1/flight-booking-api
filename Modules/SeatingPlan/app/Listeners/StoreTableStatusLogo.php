<?php

namespace Modules\SeatingPlan\Listeners;

use Modules\SeatingPlan\Events\TableUpdateStatus;

class StoreTableStatusLogo
{
    /**
     * Handle the event.
     */
    public function handle(TableUpdateStatus $event): void
    {
        $event->table->storeStatusLog(
            status: $event->status,
            changedById: $event->changedById,
            note: $event->note
        );
    }
}
