<?php

namespace Modules\Order\Delivery;

/**
 * Which customer payment methods a delivery order may use.
 *
 * Checkout, order placement and partner dispatch must agree: when a
 * third-party network delivers, AssignOrderDelivery refuses to book a rider
 * for a payment type the restaurant has not allowed for delivery. Offering
 * that payment at checkout would leave a paid order without a rider.
 */
final class DeliveryPaymentPolicy
{
    public static function allowsOnlinePayment(): bool
    {
        return ! self::thirdParty() || (bool) setting('delivery_prepaid_enabled', false);
    }

    public static function allowsCashOnDelivery(): bool
    {
        return (bool) setting('customer_payment_cod_enabled', false)
            && (! self::thirdParty() || (bool) setting('delivery_cod_enabled', false));
    }

    private static function thirdParty(): bool
    {
        return (bool) setting('third_party_delivery_enabled', false);
    }
}
