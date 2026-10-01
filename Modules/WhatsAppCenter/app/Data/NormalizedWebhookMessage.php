<?php

namespace Modules\WhatsAppCenter\Data;

final readonly class NormalizedWebhookMessage
{
    public function __construct(
        public string $providerEventId,
        public string $providerPhoneId,
        public string $sender,
        public string $type,
        public ?string $text,
        public array $safePayload,
        public ?string $providerOrderId = null,
        public ?string $catalogId = null,
        public array $orderItems = [],
        public ?array $location = null,
    ) {}
}
