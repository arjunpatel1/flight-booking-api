<?php

namespace Modules\Notification\Services\WhatsApp;

use Modules\Notification\Enums\WhatsAppProvider;
use Modules\Notification\Services\WhatsApp\Providers\MetaWhatsAppProvider;
use Modules\Notification\Services\WhatsApp\Providers\Msg91Provider;
use Modules\Notification\Services\WhatsApp\Providers\NexMsgProvider;
use Modules\Notification\Services\WhatsApp\Providers\TwilioWhatsAppProvider;

class WhatsAppProviderFactory
{
    public function make(?WhatsAppProvider $provider = null): WhatsAppProviderInterface
    {
        $provider ??= WhatsAppProvider::tryFrom(setting('whatsapp_provider') ?: WhatsAppProvider::Msg91->value)
            ?: WhatsAppProvider::Msg91;

        return match ($provider) {
            WhatsAppProvider::Msg91 => app(Msg91Provider::class),
            WhatsAppProvider::NexMsg => app(NexMsgProvider::class),
            WhatsAppProvider::Meta => app(MetaWhatsAppProvider::class),
            WhatsAppProvider::Twilio => app(TwilioWhatsAppProvider::class),
        };
    }
}
