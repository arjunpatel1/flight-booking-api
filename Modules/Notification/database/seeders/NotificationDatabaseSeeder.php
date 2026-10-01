<?php

namespace Modules\Notification\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Setting\Models\Setting;

class NotificationDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            $this->template('order_submitted', 'Order Submitted', 'order', 'order_submitted', ['customer_name', 'order_id', 'order_total']),
            $this->template('order_accepted', 'Order Accepted', 'order', 'order_accepted', ['customer_name', 'order_id', 'estimated_time']),
            $this->template('order_preparing', 'Order Preparing', 'order', 'preparing', ['customer_name', 'order_id']),
            $this->template('order_ready', 'Order Ready', 'order', 'ready', ['customer_name', 'order_id']),
            $this->template('order_completed', 'Order Completed', 'order', 'completed', ['customer_name', 'order_id', 'order_total', 'rating_link']),
            $this->template('order_cancelled', 'Order Cancelled', 'order', 'cancelled', ['customer_name', 'order_id', 'reason']),
            $this->template('billing_sent', 'Billing Sent', 'billing', 'billing_sent', ['customer_name', 'invoice_number', 'bill_total', 'payment_link']),
            $this->template('payment_received', 'Payment Received', 'billing', 'payment_received', ['customer_name', 'invoice_number', 'amount']),
            $this->template('promotion_offer', 'Promotion Offer', 'marketing', 'promotion', ['customer_name', 'offer_title', 'coupon_code', 'valid_until']),
            $this->template('coupon_sent', 'Coupon Sent', 'marketing', 'coupon', ['customer_name', 'coupon_code', 'discount_value', 'valid_until']),
            $this->template('gift_added', 'Gift Added', 'marketing', 'gift', ['customer_name', 'gift_name', 'valid_until']),
            $this->template('loyalty_reward', 'Loyalty Reward', 'crm', 'reward', ['customer_name', 'reward_name', 'points_balance']),
            $this->template('birthday_offer', 'Birthday Offer', 'crm', 'birthday', ['customer_name', 'offer_title', 'coupon_code']),
            $this->template('anniversary_offer', 'Anniversary Offer', 'crm', 'anniversary', ['customer_name', 'offer_title', 'coupon_code']),
            $this->template('inactive_customer_offer', 'Inactive Customer Offer', 'crm', 'inactive_customer', ['customer_name', 'coupon_code', 'valid_until']),
            $this->template('feedback_request', 'Feedback Request', 'crm', 'feedback_request', ['customer_name', 'order_id', 'feedback_link']),
            $this->template('table_booking_confirmed', 'Table Booking Confirmed', 'order', 'table_booking_confirmed', ['customer_name', 'booking_date', 'booking_time', 'table_name']),
        ];

        $existing = collect(Setting::get('whatsapp_templates', []))
            ->map(fn($template) => is_string($template) ? $this->template($template, $template, 'custom', $template, []) : $template)
            ->keyBy(fn(array $template) => $template['id'] ?? $template['name'] ?? null);

        $defaultById = collect($defaults)->keyBy('id');
        $merged = $defaultById
            ->keyBy('id')
            ->merge($existing)
            ->map(function (array $template, string $id) use ($defaultById) {
                $default = $defaultById->get($id);
                if ($default) {
                    $template['variables'] = array_values(array_unique([
                        ...($default['variables'] ?? []),
                        ...($template['variables'] ?? []),
                    ]));
                    $componentKeys = $template['component_keys'] ?? [];
                    $template['component_keys'] = count($componentKeys) >= count($template['variables'])
                        ? $componentKeys
                        : array_map(fn($index) => 'body_' . ($index + 1), array_keys($template['variables']));
                }

                return $template;
            })
            ->values()
            ->all();

        Setting::set('whatsapp_templates', $merged);

        if (blank(Setting::get('feedback_rating_url_template'))) {
            Setting::set('feedback_rating_url_template', rtrim((string) (Setting::get('frontend_url') ?: env('FRONTEND_URL') ?: config('app.url')), '/') . '/feedback/orders/{order_id}?source=whatsapp');
        }

        $this->setDefault('whatsapp_provider', 'msg91');
        $this->setDefault('whatsapp_msg91_api_url', config('notification.providers.msg91.api_url'));
        $this->setDefault('order_tracking_url_template', rtrim((string) (Setting::get('frontend_url') ?: env('FRONTEND_URL') ?: config('app.url')), '/') . '/orders/{order_reference}');
        $this->setDefault('order_whatsapp_estimated_time', '');
        $this->setDefault('order_whatsapp_customer_fallback', 'Customer');
        $this->setDefault('crm_inactive_customer_days', 30);
        $this->setDefault('crm_recent_customer_days', 7);
        $this->setDefault('crm_high_value_customer_min_spend', 500);
        $this->setDefault('crm_inactive_customer_automation_enabled', false);
        $this->setDefault('crm_inactive_customer_automation_time', '10:00');
        $this->setDefault('crm_inactive_customer_coupon_code', '');
        $this->setDefault('crm_inactive_customer_offer_title', '');
        $this->setDefault('crm_inactive_customer_offer_valid_until', '');
        $this->setDefault('crm_birthday_offer_automation_enabled', false);
        $this->setDefault('crm_birthday_offer_automation_time', '09:00');
        $this->setDefault('crm_birthday_offer_coupon_code', '');
        $this->setDefault('crm_birthday_offer_title', '');
        $this->setDefault('crm_anniversary_offer_automation_enabled', false);
        $this->setDefault('crm_anniversary_offer_automation_time', '09:00');
        $this->setDefault('crm_anniversary_offer_coupon_code', '');
        $this->setDefault('crm_anniversary_offer_title', '');
    }

    private function template(string $id, string $name, string $category, string $event, array $variables): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'category' => $category,
            'event' => $event,
            'is_active' => true,
            'namespace' => null,
            'language_code' => 'en',
            'variables' => $variables,
            'component_keys' => array_map(fn($index) => 'body_' . ($index + 1), array_keys($variables)),
        ];
    }

    private function setDefault(string $key, mixed $value): void
    {
        if (blank(Setting::get($key))) {
            Setting::set($key, $value);
        }
    }
}
