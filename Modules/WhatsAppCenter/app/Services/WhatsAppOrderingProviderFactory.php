<?php

namespace Modules\WhatsAppCenter\Services;

use InvalidArgumentException;
use Modules\WhatsAppCenter\Contracts\WhatsAppOrderingProvider;
use Modules\WhatsAppCenter\Services\Providers\MetaOrderingProvider;
use Modules\WhatsAppCenter\Services\Providers\Msg91OrderingProvider;
use Modules\WhatsAppCenter\Services\Providers\NexMsgOrderingProvider;

class WhatsAppOrderingProviderFactory
{
    public function make(string $provider): WhatsAppOrderingProvider
    {
        return match ($provider) {
            'meta' => app(MetaOrderingProvider::class),
            'msg91' => app(Msg91OrderingProvider::class),
            'nexmsg' => app(NexMsgOrderingProvider::class),
            default => throw new InvalidArgumentException('Unsupported WhatsApp provider.'),
        };
    }
}
