<?php

namespace Modules\Saas\Logging;

use Illuminate\Log\Logger;

/**
 * Monolog tap that attaches the TenantLogProcessor to a log channel.
 *
 * Referenced from config/logging.php (`'tap' => [TenantLogTap::class]`) so tenant
 * context enrichment applies to a channel without any per-call change. Additive:
 * it only pushes a processor.
 */
class TenantLogTap
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new TenantLogProcessor());
    }
}
