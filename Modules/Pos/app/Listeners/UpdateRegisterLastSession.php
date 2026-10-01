<?php

namespace Modules\Pos\Listeners;

use Illuminate\Support\Facades\Cache;
use Modules\Pos\Events\ClosePosSession;
use Modules\Pos\Events\OpenPosSession;
use Modules\Pos\Models\PosRegister;

class UpdateRegisterLastSession
{
    /**
     * Handle the event.
     */
    public function handle(OpenPosSession|ClosePosSession $event): void
    {
        if ($event instanceof OpenPosSession) {
            PosRegister::query()
                ->withOutGlobalBranchPermission()
                ->where('id', $event->session->pos_register_id)
                ->update(['last_session_id' => $event->session->id]);
        }

        Cache::tags('pos_registers')->flush();
    }
}
