<?php

namespace Modules\Flight\Exceptions;

use Exception;

class QuotaExceededException extends Exception
{
    public function __construct(
        public readonly string $metric,
        public readonly ?int $limit,
        public readonly int $currentUsed,
        public readonly int $requestedUnits = 1,
        string $message = 'Subscription quota limit exceeded.'
    ) {
        $limitStr = $limit === null ? 'Unlimited' : (string) $limit;
        parent::__construct(
            "{$message} Metric: [{$metric}], Limit: {$limitStr}, Used: {$currentUsed}, Requested: {$requestedUnits}.",
            403
        );
    }
}
