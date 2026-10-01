<?php

namespace Modules\Payment\Gateways;

/**
 * Outcome of a card-present terminal transaction.
 */
enum GatewayPaymentStatus: string
{
    case Approved = 'approved';
    case Declined = 'declined';
    case Pending = 'pending';
    case Failed = 'failed';
}
