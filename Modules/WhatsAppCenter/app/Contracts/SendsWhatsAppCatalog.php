<?php

namespace Modules\WhatsAppCenter\Contracts;

use Modules\WhatsAppCenter\Models\WhatsAppPhoneNumber;
use Modules\WhatsAppCenter\Models\WhatsAppProviderProfile;

interface SendsWhatsAppCatalog
{
    public function sendCatalog(
        WhatsAppProviderProfile $profile,
        WhatsAppPhoneNumber $phoneNumber,
        string $recipient,
        string $bodyText,
        string $footerText,
        ?string $operationId = null,
    ): array;
}
