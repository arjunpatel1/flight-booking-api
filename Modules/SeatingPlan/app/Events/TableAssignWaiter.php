<?php

namespace Modules\SeatingPlan\Events;

use Modules\SeatingPlan\Models\Table;

class TableAssignWaiter
{
    /**
     * Create a new event instance.
     *
     * @param Table $table
     * @param int|null $waiterId
     * @param int|null $oldWaiterId
     */
    public function __construct(public Table $table,
                                public ?int  $waiterId = null,
                                public ?int  $oldWaiterId = null)
    {
    }
}
