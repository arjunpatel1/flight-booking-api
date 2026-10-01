<?php

namespace Modules\Order\Support;

use Modules\Order\Models\Order;
use Modules\User\Enums\DefaultRole;

class OrderSourcePresenter
{
    public static function make(Order $order): array
    {
        if ($order->relationLoaded('whatsAppOrderSession') && $order->whatsAppOrderSession) {
            return self::source('whatsapp');
        }

        if ($order->relationLoaded('partnerApiOrderMapping') && $order->partnerApiOrderMapping) {
            return self::source('partner');
        }

        $mapping = $order->relationLoaded('aggregatorOrderMapping')
            ? $order->aggregatorOrderMapping
            : null;

        $integration = $mapping?->relationLoaded('integration') ? $mapping->integration : null;
        if ($integration) {
            return [
                'source_type' => 'aggregator',
                'source_label' => $integration->provider->trans(),
                'source_color' => self::aggregatorColor($integration),
                'aggregator_provider' => $integration->provider->value,
            ];
        }

        $explicitSource = self::normaliseSource(data_get($order->fulfilmentDetails(), 'source'))
            ?? self::normaliseSource(data_get($order->fulfilmentDetails(), 'channel'))
            ?? self::normaliseSource(data_get($order->fulfilmentDetails(), 'created_from'));

        if ($explicitSource) {
            return self::source($explicitSource);
        }

        if (self::looksLikePublicCustomerOrder($order)) {
            return $order->table_id
                ? self::source('qr')
                : self::source('customer_web');
        }

        if ($order->waiter_id && (int) $order->created_by === (int) $order->waiter_id) {
            return self::source('waiter_app');
        }

        if ($order->pos_register_id || $order->pos_session_id) {
            return self::source('pos');
        }

        if (is_null($order->created_by)) {
            return $order->table_id
                ? self::source('qr')
                : self::source('portal');
        }

        return self::source('admin');
    }

    private static function source(string $type): array
    {
        $sources = [
            'admin' => ['order::orders.sources.admin', '#0F172A'],
            'waiter_app' => ['order::orders.sources.waiter_app', '#2563EB'],
            'customer_app' => ['order::orders.sources.customer_app', '#06B6D4'],
            'customer_web' => ['order::orders.sources.customer_web', '#0EA5E9'],
            'qr' => ['order::orders.sources.qr', '#7C3AED'],
            'whatsapp' => ['order::orders.sources.whatsapp', '#25D366'],
            'partner' => ['order::orders.sources.partner', '#7C3AED'],
            'portal' => ['order::orders.sources.portal', '#0EA5E9'],
            'pos' => ['order::orders.sources.pos', '#ff6b00'],
        ];

        [$label, $fallbackColor] = $sources[$type] ?? [null, '#64748b'];

        return self::direct(
            $type,
            $label ? __($label) : str($type)->replace('_', ' ')->title()->toString(),
            self::sourceColor($type, $fallbackColor)
        );
    }

    private static function normaliseSource(mixed $source): ?string
    {
        $value = str($source ?? '')->lower()->replace(['-', ' '], '_')->trim('_')->toString();

        return match ($value) {
            'admin', 'backoffice', 'back_office' => 'admin',
            'waiter', 'waiter_app', 'waiterapp' => 'waiter_app',
            'customer_app', 'customerapp', 'mobile_app', 'mobile' => 'customer_app',
            'customer_web', 'customer_ordering', 'online_menu', 'web', 'website' => 'customer_web',
            'qr', 'qr_order', 'table_qr', 'table_qr_order' => 'qr',
            'whatsapp', 'whatsapp_order' => 'whatsapp',
            'partner', 'partner_api', 'partner_api_order' => 'partner',
            'portal' => 'portal',
            'pos', 'pos_terminal' => 'pos',
            default => null,
        };
    }

    private static function looksLikePublicCustomerOrder(Order $order): bool
    {
        if ($order->pos_register_id || $order->pos_session_id) {
            return false;
        }

        if (is_null($order->created_by)) {
            return false;
        }

        $creator = $order->relationLoaded('createdBy') ? $order->createdBy : null;
        if ($creator && method_exists($creator, 'hasRole')) {
            return $creator->hasRole(DefaultRole::Customer->value);
        }

        return $order->customer_id && (int) $order->created_by === (int) $order->customer_id;
    }

    private static function direct(string $type, string $label, string $color): array
    {
        return [
            'source_type' => $type,
            'source_label' => $label,
            'source_color' => $color,
            'aggregator_provider' => null,
        ];
    }

    private static function aggregatorColor($integration): string
    {
        $provider = $integration->provider?->value;
        $colors = setting('aggregator_provider_colors') ?: [];

        return $integration->settings['source_color']
            ?? ($provider ? ($colors[$provider] ?? null) : null)
            ?? '#64748b';
    }

    private static function sourceColor(string $source, string $fallback): string
    {
        static $colors = null;

        $colors ??= setting('order_source_colors') ?: [];

        return $colors[$source] ?? $fallback;
    }
}
