<?php

namespace Modules\Pos\Events;

use Modules\Pos\Models\PosSession;

class OpenPosSession
{
    /**
     * Create a new event instance.
     *
     * @param PosSession $session
     */
    public function __construct(public PosSession $session)
    {
    }
}
