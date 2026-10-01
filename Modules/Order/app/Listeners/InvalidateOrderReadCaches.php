<?php

namespace Modules\Order\Listeners;

use Illuminate\Support\Facades\Cache;

/**
 * Order broadcasts cause POS/KDS clients to refetch immediately. Invalidate
 * the cached projections before those clients arrive so realtime never serves
 * the previous status for another minute.
 */
class InvalidateOrderReadCaches
{
    public function handle(object $event): void
    {
        Cache::tags(['orders', 'kitchen'])->flush();
    }
}
