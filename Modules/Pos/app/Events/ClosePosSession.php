<?php

namespace Modules\Pos\Events;

use Modules\Pos\Models\PosSession;

class ClosePosSession
{
    /**
     * Create a new event instance.
     *
     * @param PosSession $session
     */
    public function __construct(protected PosSession $session)
    {
    }
}
