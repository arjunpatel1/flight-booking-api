<?php

namespace Modules\Notification\Services\WhatsApp;

class WhatsAppTemplateCatalog
{
    public static function defaults(): array
    {
        return [
            self::template('staff_order_received', 'Staff New Order Alert', 'utility', 'staff_order_received', 'Clear staff-only summary with source, items, delivery slot, instructions, outlet and total.', "*New Order Received* 🍽️\n\nOrder ID: *{{order_number}}*\n{{source}}\nOutlet: *{{branch_name}}*\nTotal: *{{order_total}}*\n\nReview the order before preparing it.", ['order_number', 'source', 'branch_name', 'order_total']),
            [...self::template('staff_order_received_link', 'Staff New Order Alert with Link', 'utility', 'staff_order_received', 'Requires a separately approved five-variable provider template. The link opens the exact order in the tenant admin.', "New Restaurant Order Received.\n\nOrder number: {{order_number}}\nOrder source: {{source}}\nOutlet: {{branch_name}}\nOrder total: {{order_total}}\n\nOpen order: {{order_link}}\n\nPlease review the order and payment before preparing it.", ['order_number', 'source', 'branch_name', 'order_total', 'order_link']), 'is_active' => false],
            [...self::template('staff_order_received_items', 'Staff New Order Alert with Items', 'utility', 'staff_order_received', 'Preferred approved template with a dedicated compact item and quantity summary.', "*New Order Received* 🔔\n\nOrder: *{{order_number}}*\nSource: *{{source}}*\nItems: *{{items}}*\nOutlet: *{{branch_name}}*\nTotal: *{{order_total}}*\n\nReview payment and send the order to the kitchen.", ['order_number', 'source', 'items', 'branch_name', 'order_total']), 'is_active' => false],
            [...self::template('staff_order_received_items_link', 'Staff New Order Alert with Items and Link', 'utility', 'staff_order_received', 'Preferred approved template with item quantities and a secure Open Order button.', "*New Order Received* 🔔\n\nOrder: *{{order_number}}*\nSource: *{{source}}*\nItems: *{{items}}*\nOutlet: *{{branch_name}}*\nTotal: *{{order_total}}*\n\nReview payment and send the order to the kitchen.", ['order_number', 'source', 'items', 'branch_name', 'order_total', 'order_link'], ['body_1', 'body_2', 'body_3', 'body_4', 'body_5', 'button_url_1'], [['type' => 'url', 'index' => 0, 'label' => 'Open Order', 'variable' => 'order_link']]), 'is_active' => false],
            [...self::template('staff_order_received_professional', 'Staff New Order — Detailed', 'utility', 'staff_order_received', 'Professional staff alert with items, payment, fulfilment, order time and a secure Open Order button.', "*New Restaurant Order* 🔔\n\nOrder: *{{order_number}}*\nSource: *{{source}}*\nItems: *{{items}}*\nPayment: *{{payment_status}}*\nFulfilment: *{{fulfilment}}*\nOutlet: *{{branch_name}}*\nTotal: *{{order_total}}*\nOrdered at: *{{ordered_at}}*\n\nReview payment and send the order to the kitchen.", ['order_number', 'source', 'items', 'payment_status', 'fulfilment', 'branch_name', 'order_total', 'ordered_at', 'order_link'], ['body_1', 'body_2', 'body_3', 'body_4', 'body_5', 'body_6', 'body_7', 'body_8', 'button_url_1'], [['type' => 'url', 'index' => 0, 'label' => 'Open Order', 'variable' => 'order_link']]), 'template_id' => 'nexdine_staff_order_details_v3', 'is_active' => false],
            [...self::template('staff_delivery_not_created', 'Delivery Order Not Created', 'utility', 'staff_delivery_not_created', 'Alerts restaurant admins when the restaurant order exists but its delivery order was not created.', "*Delivery Order Not Created* ⚠️\n\nOrder: *{{order_number}}*\nOutlet: *{{branch_name}}*\nReason: {{reason}}\n\nOpen the order, correct the issue, then use Review & Send.", ['order_number', 'branch_name', 'reason', 'order_link'], ['body_1', 'body_2', 'body_3', 'button_url_1'], [['type' => 'url', 'index' => 0, 'label' => 'Open Order', 'variable' => 'order_link']]), 'template_id' => 'nexdine_delivery_not_created_v2', 'is_active' => false],
            [...self::template('staff_delivery_rider_unassigned', 'Rider Assignment Delayed', 'utility', 'staff_delivery_rider_unassigned', 'Alerts restaurant admins when no rider is assigned within the configured assignment window.', "*Rider Assignment Delayed* ⚠️\n\nOrder: *{{order_number}}*\nOutlet: *{{branch_name}}*\nStatus: {{reason}}\n\nOpen the order and review the delivery.", ['order_number', 'branch_name', 'reason', 'order_link'], ['body_1', 'body_2', 'body_3', 'button_url_1'], [['type' => 'url', 'index' => 0, 'label' => 'Open Order', 'variable' => 'order_link']]), 'template_id' => 'nexdine_rider_assignment_delayed_v1', 'is_active' => false],
            [...self::template('staff_delivery_cancelled', 'Delivery Cancelled', 'utility', 'staff_delivery_cancelled', 'Alerts restaurant admins when the delivery partner cancels a delivery task.', "*Delivery Cancelled* ❌\n\nOrder: *{{order_number}}*\nOutlet: *{{branch_name}}*\nReason: {{reason}}\n\nOpen the order and arrange the next action.", ['order_number', 'branch_name', 'reason', 'order_link'], ['body_1', 'body_2', 'body_3', 'button_url_1'], [['type' => 'url', 'index' => 0, 'label' => 'Open Order', 'variable' => 'order_link']]), 'template_id' => 'nexdine_delivery_cancelled_admin_v1', 'is_active' => false],
            [...self::template('customer_login_otp', 'Customer Login OTP', 'authentication', 'customer_otp', 'Secure OTP for customer sign-in and checkout verification.', "*OTP Verification* 🔐\n\nYour one-time verification code is *{{otp}}*. It expires in 5 minutes. Never share this code.", ['otp', 'button_code'], ['body_1', 'button_1']), 'template_id' => 'auth_login_verification'],
            [...self::template('whatsapp_order_payment', 'WhatsApp Order Payment', 'utility', 'whatsapp_order_received_payment', 'WhatsApp catalogue receipt with secure Pay Now and in-chat Cancel Order confirmation.', "*Order Received — Payment Required* 🧾\n\nHi {{customer_name}},\nOrder: *{{order_id}}*\nRestaurant: *{{restaurant_name}}*\nTotal: *{{order_total}}*\n\nPayment link will be valid for only 30 min.", ['customer_name', 'order_id', 'restaurant_name', 'order_total', 'payment_token'], ['body_1', 'body_2', 'body_3', 'body_4', 'button_url_1'], [['type' => 'url', 'index' => 0, 'label' => 'Pay Now', 'variable' => 'payment_token'], ['type' => 'quick_reply', 'index' => 1, 'label' => 'Cancel Order']]), 'template_id' => 'nexdine_order_payment_cancel_chat_v3'],
            [...self::template('whatsapp_order_received', 'Order Received (WhatsApp Order)', 'utility', 'whatsapp_order_received', 'WhatsApp catalogue receipt with Track Order and an in-chat Cancel action. Cancellation always asks for confirmation and rechecks kitchen status.', "*Order Received (WhatsApp Order)* ✅\n\nHi {{customer_name}},\nThank you for choosing {{restaurant_name}}.\nOrder: *{{order_id}}*\nTotal: *{{order_total}}*", ['customer_name', 'order_id', 'restaurant_name', 'order_total', 'tracking_link', 'cancel_token'], ['body_1', 'body_2', 'body_3', 'body_4', 'button_url_1', 'button_quick_reply_2'], [['type' => 'url', 'index' => 0, 'label' => 'Track Order', 'variable' => 'tracking_link'], ['type' => 'quick_reply', 'index' => 1, 'label' => 'Cancel Order', 'variable' => 'cancel_token']]), 'template_id' => 'nexdine_order_received_actions_v2'],
            self::template('order_submitted', 'Order Received (Link Order)', 'utility', 'order_submitted', 'Confirms a customer web or shared-link order with one secure Track Order button.', "*Order Received (Link Order)* ✅\n\nHi {{customer_name}},\nThank you for choosing {{restaurant_name}}.\nOrder: *{{order_id}}*\nTotal: *{{order_total}}*\n\nTrack your order below.", ['customer_name', 'restaurant_name', 'order_id', 'order_total', 'tracking_link'], ['body_1', 'body_2', 'body_3', 'body_4', 'button_url_1'], [['type' => 'url', 'index' => 0, 'label' => 'Track Order', 'variable' => 'tracking_link']]),
            [...self::template('order_accepted', 'Order Confirmed', 'utility', 'order_accepted', 'Notifies the customer when the restaurant accepts an order and keeps tracking one tap away.', "*Order Confirmed* ✅\n\nHi {{customer_name}}, order *{{order_id}}* has been accepted.\n\nEstimated preparation time: *{{estimated_time}}*", ['customer_name', 'order_id', 'estimated_time', 'tracking_link'], ['body_1', 'body_2', 'body_3', 'button_url_1'], [['type' => 'url', 'index' => 0, 'label' => 'Track Order', 'variable' => 'tracking_link']]), 'template_id' => 'nexdine_order_confirmed_track_v2'],
            [...self::template('order_preparing', 'Preparing Order', 'utility', 'preparing', 'Notifies the customer when kitchen preparation begins and provides live tracking.', "*Preparing Order* 👨‍🍳\n\nHi {{customer_name}}, our kitchen has started preparing order *{{order_id}}*.", ['customer_name', 'order_id', 'tracking_link'], ['body_1', 'body_2', 'button_url_1'], [['type' => 'url', 'index' => 0, 'label' => 'Track Order', 'variable' => 'tracking_link']]), 'template_id' => 'nexdine_order_preparing_track_v2'],
            [...self::template('order_ready', 'Order Ready', 'utility', 'ready', 'Notifies the customer when an order is ready and provides live tracking.', "*Order Ready* 🛎️\n\nHi {{customer_name}}, order *{{order_id}}* is ready.\n\nFor delivery orders, please wait for rider updates.", ['customer_name', 'order_id', 'tracking_link'], ['body_1', 'body_2', 'button_url_1'], [['type' => 'url', 'index' => 0, 'label' => 'Track Order', 'variable' => 'tracking_link']]), 'template_id' => 'nexdine_order_ready_track_v2'],
            self::template(
                'order_completed',
                'Order Completed & Feedback',
                'utility',
                'completed',
                'Confirms a completed order and offers one-tap feedback.',
                "*Order Completed* ✅\n\nThank you, {{customer_name}}.\nOrder: *{{order_id}}*\nTotal paid: *{{order_total}}*\n\nWe would love your feedback.",
                ['customer_name', 'order_id', 'order_total'],
                ['body_1', 'body_2', 'body_3'],
                [
                    ['type' => 'quick_reply', 'index' => 0, 'label' => 'Yes, Satisfied'],
                    ['type' => 'quick_reply', 'index' => 1, 'label' => 'No, Need Help'],
                ],
            ),
            self::template('order_cancelled', 'Order Cancelled', 'utility', 'cancelled', 'Explains that an order was cancelled and includes the reason.', "*Order Cancelled* ❌\n\nHi {{customer_name}},\nOrder *{{order_id}}* has been cancelled.\nReason: {{reason}}\n\nPlease contact the restaurant if you need assistance.", ['customer_name', 'order_id', 'reason']),
            self::template('billing_sent', 'Payment Received', 'utility', 'billing_sent', 'Confirms payment and provides the invoice through a secure button.', "*Payment Received* ✅\n\nHi {{customer_name}}, your payment is confirmed.\nInvoice: *{{invoice_number}}*\nAmount paid: *{{bill_total}}*", ['customer_name', 'invoice_number', 'bill_total', 'payment_link'], ['body_1', 'body_2', 'body_3', 'button_url_1'], [['type' => 'url', 'index' => 0, 'label' => 'Invoice', 'variable' => 'payment_link']]),
            self::template('payment_received', 'Payment Received', 'utility', 'payment_received', 'Confirms a successfully collected customer payment.', "*Payment Received* ✅\n\nThank you, {{customer_name}}.\nAmount: *{{amount}}*\nInvoice: *{{invoice_number}}*\n\nYour payment has been recorded successfully.", ['customer_name', 'amount', 'invoice_number']),
            self::template('payment_failed', 'Payment Failed', 'utility', 'payment_failed', 'Tells the customer that payment failed and can be retried.', "*Payment Failed* ⚠️\n\nHi {{customer_name}}, we could not confirm payment for order *{{order_id}}*.\n\nNo successful payment was recorded. Please Retry Payment.", ['customer_name', 'order_id']),
            self::template('refund_processed', 'Refund Processed', 'utility', 'refund_processed', 'Confirms a refund and the refunded amount.', "*Refund Processed* ✅\n\nHi {{customer_name}},\nOrder: *{{order_id}}*\nRefund amount: *{{amount}}*\n\nThe refund has been processed. Your bank's settlement time may apply.", ['customer_name', 'order_id', 'amount']),
            self::template('table_booking_confirmed', 'Reservation Confirmed', 'utility', 'table_booking_confirmed', 'Confirms the date, time and table for a reservation.', "*Reservation Confirmed* 🍽️\n\nHi {{customer_name}},\nDate: *{{booking_date}}*\nTime: *{{booking_time}}*\nTable: *{{table_name}}*\n\nWe look forward to welcoming you.", ['customer_name', 'booking_date', 'booking_time', 'table_name']),
            self::template('table_booking_reminder', 'Reservation Reminder', 'utility', 'table_booking_reminder', 'Reminds the customer about an upcoming reservation.', "*Reservation Reminder* ⏰\n\nHi {{customer_name}}, this is a reminder for your upcoming visit.\n\nDate: *{{booking_date}}*\nTime: *{{booking_time}}*\nTable: *{{table_name}}*\n\nWe look forward to serving you.", ['customer_name', 'booking_date', 'booking_time', 'table_name']),
            self::template('table_booking_cancelled', 'Reservation Cancelled', 'utility', 'table_booking_cancelled', 'Confirms that a reservation was cancelled.', "*Reservation Cancelled* ❌\n\nHi {{customer_name}}, your reservation for *{{booking_date}}* at *{{booking_time}}* has been cancelled.\n\nContact the restaurant if you would like to book another time.", ['customer_name', 'booking_date', 'booking_time']),
            self::template('promotion_offer', 'Promotion Offer', 'marketing', 'promotion', 'Sends an eligible promotional offer to a customer.', "*A Special Offer For You* 🎉\n\nHi {{customer_name}},\n{{offer_title}}\n\nUse code: *{{coupon_code}}*\nValid until: {{valid_until}}\n\nTerms and eligibility apply.", ['customer_name', 'offer_title', 'coupon_code', 'valid_until']),
            self::template('coupon_sent', 'Coupon Sent', 'marketing', 'coupon', 'Sends a coupon code with its discount and validity.', "*Your Coupon Is Ready* 🎟️\n\nHi {{customer_name}}, save *{{discount_value}}* on your next eligible order.\n\nCode: *{{coupon_code}}*\nValid until: {{valid_until}}\n\nTerms and eligibility apply.", ['customer_name', 'discount_value', 'coupon_code', 'valid_until']),
            self::template('gift_added', 'Gift Added', 'marketing', 'gift', 'Notifies a customer that a gift was added to their account.', "*A Gift For You* 🎁\n\nHi {{customer_name}}, *{{gift_name}}* has been added to your account.\n\nValid until: {{valid_until}}\nWe hope you enjoy it!", ['customer_name', 'gift_name', 'valid_until']),
            self::template('loyalty_reward', 'Loyalty Reward', 'marketing', 'reward', 'Notifies a customer about a loyalty reward and points balance.', "*Loyalty Reward Unlocked* ⭐\n\nHi {{customer_name}}, you unlocked: *{{reward_name}}*\n\nAvailable points: *{{points_balance}}*\nThank you for being a valued customer.", ['customer_name', 'reward_name', 'points_balance']),
            self::template('birthday_offer', 'Birthday Offer', 'marketing', 'birthday', 'Sends the configured birthday greeting and coupon.', "*Happy Birthday, {{customer_name}}!* 🎂\n\n{{offer_title}}\n\nUse code: *{{coupon_code}}*\nWe hope your day is wonderful.", ['customer_name', 'offer_title', 'coupon_code']),
            self::template('anniversary_offer', 'Anniversary Offer', 'marketing', 'anniversary', 'Sends the configured anniversary greeting and coupon.', "*Happy Anniversary, {{customer_name}}!* 💐\n\n{{offer_title}}\n\nUse code: *{{coupon_code}}*\nThank you for celebrating with us.", ['customer_name', 'offer_title', 'coupon_code']),
            self::template('inactive_customer_offer', 'Inactive Customer Offer', 'marketing', 'inactive_customer', 'Invites an inactive customer back with an offer.', "*We Miss You, {{customer_name}}* 👋\n\nEnjoy a special welcome-back offer.\n\nCode: *{{coupon_code}}*\nValid until: {{valid_until}}\n\nWe would love to serve you again.", ['customer_name', 'coupon_code', 'valid_until']),
            [...self::template('feedback_request', 'Feedback Request', 'utility', 'feedback_request', 'Requests feedback after an order is completed with a secure rating button.', "*How Was Your Order?*\n\nHi {{customer_name}}, we hope you enjoyed order *{{order_id}}*.\n\nYour feedback helps us improve.", ['customer_name', 'order_id', 'feedback_link'], ['body_1', 'body_2', 'button_url_1'], [['type' => 'url', 'index' => 0, 'label' => 'Rate Order', 'variable' => 'feedback_link']]), 'template_id' => 'feedback_request_v2'],
            self::template('address_delivery_update', 'Delivery Update', 'utility', 'delivery_update', 'Shares rider assignment and every customer-visible delivery milestone with a Track Order button.', "*Delivery Update* 🛵\n\nHi {{customer_name}},\nOrder: *{{order_id}}*\nStatus: *{{delivery_status}}*\n\nUse the button below for live order tracking and rider details.", ['customer_name', 'order_id', 'delivery_status', 'tracking_link'], ['body_1', 'body_2', 'body_3', 'button_url_1'], [['type' => 'url', 'index' => 0, 'label' => 'Track Order', 'variable' => 'tracking_link']]),
        ];
    }

    public static function merge(array $configured): array
    {
        $existing = collect($configured)
            ->map(fn ($item) => is_string($item) ? ['id' => $item, 'template_id' => $item, 'name' => $item] : $item)
            ->keyBy(fn (array $item) => $item['id'] ?? $item['event'] ?? $item['template_id'] ?? $item['name'] ?? null);

        return collect(self::defaults())->map(function (array $default) use ($existing) {
            $current = $existing->pull($default['id']);
            if (is_array($current) && is_string($current['message'] ?? null)) {
                $legacyHeadings = [
                    '*New order received*' => '*New Order Received*',
                    '*New restaurant order*' => '*New Restaurant Order*',
                    'New restaurant order received.' => 'New Restaurant Order Received.',
                    '*Order received' => '*Order Received',
                    '*Order confirmed*' => '*Order Confirmed*',
                    '*Preparing order*' => '*Preparing Order*',
                    '*Order ready*' => '*Order Ready*',
                    '*Order completed*' => '*Order Completed*',
                    '*Order cancelled*' => '*Order Cancelled*',
                    '*Payment received*' => '*Payment Received*',
                    '*Payment failed*' => '*Payment Failed*',
                    '*Refund processed*' => '*Refund Processed*',
                    '*Reservation confirmed*' => '*Reservation Confirmed*',
                    '*Reservation reminder*' => '*Reservation Reminder*',
                    '*Reservation cancelled*' => '*Reservation Cancelled*',
                    '*A special offer for you*' => '*A Special Offer For You*',
                    '*Your coupon is ready*' => '*Your Coupon Is Ready*',
                    '*A gift for you*' => '*A Gift For You*',
                    '*Loyalty reward unlocked*' => '*Loyalty Reward Unlocked*',
                    '*Happy birthday,' => '*Happy Birthday,',
                    '*Happy anniversary,' => '*Happy Anniversary,',
                    '*We miss you,' => '*We Miss You,',
                    '*How was your order?*' => '*How Was Your Order?*',
                    '*Delivery update*' => '*Delivery Update*',
                ];
                $current['message'] = str_replace(
                    array_keys($legacyHeadings),
                    array_values($legacyHeadings),
                    $current['message'],
                );
            }
            $legacyCompletedMessages = [
                "*Order completed* ✅\n\nThank you, {{customer_name}}.\nOrder: *{{order_id}}*\nTotal paid: *{{order_total}}*\n\nWe would love your feedback:\n{{rating_link}}",
                "*Order completed* ✅\n\nThank you, {{customer_name}}.\nOrder: *{{order_id}}*\nTotal paid: *{{order_total}}*\n\nWe would love your feedback.",
            ];
            if ($default['id'] === 'order_completed'
                && is_array($current)
                && in_array(($current['message'] ?? null), $legacyCompletedMessages, true)) {
                // Upgrade only NexDine's former stock template. Restaurant-authored
                // wording and the provider-approved template ID remain untouched.
                $current['name'] = $default['name'];
                $current['description'] = $default['description'];
                $current['message'] = $default['message'];
                $current['variables'] = $default['variables'];
                $current['component_keys'] = $default['component_keys'];
                $current['buttons'] = $default['buttons'];
            }
            if ($default['id'] === 'billing_sent'
                && is_array($current)
                && (array_values($current['variables'] ?? []) === ['greeting', 'customer_name', 'invoice_number', 'bill_total', 'payment_link']
                    || array_values($current['variables'] ?? []) === ['customer_name', 'invoice_number', 'bill_total', 'payment_link'])) {
                // Meta expects four body slots and a separate URL-button slot.
                // Upgrade former all-body mappings while preserving tenant and
                // provider identity metadata.
                $current['name'] = $default['name'];
                $current['description'] = $default['description'];
                $current['message'] = $default['message'];
                $current['variables'] = $default['variables'];
                $current['component_keys'] = $default['component_keys'];
            }
            $legacyOrderSubmitted = $default['id'] === 'order_submitted'
                && is_array($current)
                && array_values($current['variables'] ?? []) === ['customer_name', 'restaurant_name', 'order_id', 'order_total']
                && ! in_array('button_url_1', (array) ($current['component_keys'] ?? []), true);
            $placeholderOrderSubmitted = $default['id'] === 'order_submitted'
                && is_array($current)
                && array_values($current['variables'] ?? []) === ['body_1', 'body_2', 'body_3', 'body_4', 'tracking_link']
                && array_values($current['component_keys'] ?? []) === ['body_1', 'body_2', 'body_3', 'body_4', 'button_url_1'];
            if ($legacyOrderSubmitted || $placeholderOrderSubmitted) {
                // The former stock mapping predated the approved Track Order
                // URL component, while an early provider sync stored positional
                // body_N aliases instead of NexDine's semantic parameter names.
                // Either shape produces empty required components at NexMsg.
                // Upgrade only these exact stock shapes so custom mappings stay
                // untouched.
                $current['name'] = $default['name'];
                $current['description'] = $default['description'];
                $current['message'] = $default['message'];
                $current['variables'] = $default['variables'];
                $current['component_keys'] = $default['component_keys'];
                $current['buttons'] = $default['buttons'];
            }
            if (! is_array($current)) {
                return $default;
            }

            // Provider catalogues describe approval/components, but normally do
            // not know NexDine's internal event key. Never let a sync erase the
            // event-to-template automation mapping for a stock template.
            if (blank($current['event'] ?? null)) {
                unset($current['event']);
            }

            return [...$default, ...$current];
        })->concat($existing->values())->values()->all();
    }

    private static function template(string $id, string $name, string $category, string $event, string $description, string $message, array $variables, ?array $componentKeys = null, array $buttons = []): array
    {
        return [
            'id' => $id,
            'template_id' => $id,
            'name' => $name,
            'category' => $category,
            'event' => $event,
            'description' => $description,
            'message' => $message,
            'is_active' => true,
            'namespace' => null,
            'language_code' => 'en',
            'variables' => $variables,
            'component_keys' => $componentKeys ?? array_map(fn ($index) => 'body_'.($index + 1), array_keys($variables)),
            'buttons' => $buttons,
        ];
    }
}
