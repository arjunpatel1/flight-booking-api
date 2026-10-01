<?php

namespace Modules\WhatsAppCenter\Support;

class WhatsAppOrderingPolicy
{
    public const DEFAULT_GREETINGS = ['hi', 'hii', 'hiii', 'hey', 'hello', 'start', 'menu'];
    public const DEFAULT_CATALOG_MESSAGE = 'Welcome! Tap View catalogue, add the items you want, review your cart, then tap Send to business.';
    public const DEFAULT_CATALOG_FOOTER = 'Tap below to browse the menu';
    public const DEFAULT_REJECTION_MESSAGE = 'Some selected items are currently unavailable. Please open the latest catalogue and choose available items, or contact the restaurant.';

    public static function greetingKeywords(array $capabilities = []): array
    {
        $configured = data_get($capabilities, 'greeting_keywords', self::DEFAULT_GREETINGS);

        return collect(is_array($configured) ? $configured : self::DEFAULT_GREETINGS)
            ->map(fn ($keyword) => mb_strtolower(trim((string) $keyword)))
            ->filter()->unique()->values()->all();
    }

    public static function isGreeting(string $text, array $capabilities = []): bool
    {
        return in_array(mb_strtolower(trim($text)), self::greetingKeywords($capabilities), true);
    }

    public static function releasesImmediately(array $capabilities = []): bool
    {
        return data_get($capabilities, 'kot_release_policy', 'after_payment_or_approval') === 'immediate';
    }

    public static function catalogMessage(string $branchName, array $capabilities = []): string
    {
        $configured = trim((string) data_get($capabilities, 'catalog_message'));

        return str_replace('{restaurant}', $branchName, $configured ?: "Welcome to {$branchName}! Tap View catalogue, add the items you want, review your cart, then tap Send to business.");
    }

    public static function orderTypePrompt(string $branchName, array $capabilities = []): string
    {
        $enabled = (array) data_get($capabilities, 'enabled_order_types', ['takeaway']);
        $choices = [];
        if (in_array('delivery', $enabled, true)) {
            $choices[] = 'Reply *DELIVERY* for home delivery';
        }
        if (in_array('takeaway', $enabled, true)) {
            $choices[] = 'Reply *PICKUP* to collect your order';
        }

        return "Welcome to {$branchName}! How would you like to receive your order?\n\n".
            implode("\n", $choices ?: ['Reply *PICKUP* to collect your order']);
    }

    public static function orderTypeChoices(array $capabilities = []): array
    {
        $enabled = (array) data_get($capabilities, 'enabled_order_types', ['takeaway']);

        return collect(['delivery' => 'delivery', 'takeaway' => 'pickup'])
            ->filter(fn ($choice, $type) => in_array($type, $enabled, true))
            ->values()->all();
    }

    public static function orderTypeButtonPrompt(string $branchName): string
    {
        return "Welcome to {$branchName}! How would you like to receive your order?";
    }

    public static function catalogFooter(array $capabilities = []): string
    {
        return trim((string) data_get($capabilities, 'catalog_footer')) ?: self::DEFAULT_CATALOG_FOOTER;
    }

    public static function rejectionMessage(array $capabilities = []): string
    {
        return trim((string) data_get($capabilities, 'catalog_rejection_message')) ?: self::DEFAULT_REJECTION_MESSAGE;
    }
}
