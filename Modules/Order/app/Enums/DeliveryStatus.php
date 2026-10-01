<?php

namespace Modules\Order\Enums;

enum DeliveryStatus: string
{
    case WaitingForAssignment = 'waiting_for_assignment';
    case FetchingQuotes = 'fetching_quotes';
    case ServiceabilityChecked = 'serviceability_checked';
    case Quoted = 'quoted';
    case Assigning = 'assigning';
    case BookingPending = 'booking_pending';
    case Booked = 'booked';
    case RiderSearching = 'rider_searching';
    case RiderAssigned = 'rider_assigned';
    case ArrivedAtPickup = 'arrived_at_pickup';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case ArrivedAtCustomer = 'arrived_at_customer';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case CancelPending = 'cancel_pending';
    case Rto = 'rto';
    case RtoCompleted = 'rto_completed';
    case ReturnedAfterDelivery = 'returned_after_delivery';
    case Failed = 'failed';
    case Investigation = 'investigation';
    case ManualReviewRequired = 'manual_review_required';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::Failed], true);
    }
}
