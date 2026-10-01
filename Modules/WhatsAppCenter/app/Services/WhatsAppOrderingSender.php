<?php

namespace Modules\WhatsAppCenter\Services;

use Modules\WhatsAppCenter\Contracts\SendsWhatsAppCatalog;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;
use Modules\WhatsAppCenter\Services\Providers\NexMsgOrderingProvider;
use RuntimeException;

class WhatsAppOrderingSender
{
    public function __construct(private readonly WhatsAppOrderingProviderFactory $providers) {}

    public function sendText(WhatsAppTenantAssignment $assignment, string $recipient, string $body): array
    {
        $assignment->loadMissing(['profile', 'phoneNumber']);
        $profile = $assignment->profile;
        abort_unless($profile && ($profile->tenant_id === null || (int) $profile->tenant_id === (int) $assignment->tenant_id), 404);
        return $this->providers->make($profile->provider)
            ->sendText($profile, $assignment->phoneNumber, $recipient, $body);
    }

    public function sendOrderReceiptButton(WhatsAppTenantAssignment $assignment, string $recipient, array $details, string $cancelToken): ?array
    {
        $assignment->loadMissing(['profile', 'phoneNumber']);
        $profile = $assignment->profile;
        if (! $profile || $profile->provider !== 'nexmsg'
            || ($profile->tenant_id !== null && (int) $profile->tenant_id !== (int) $assignment->tenant_id)) {
            return null;
        }

        $provider = $this->providers->make('nexmsg');
        if (! $provider instanceof NexMsgOrderingProvider) {
            return null;
        }

        return $provider->sendOrderReceiptButton(
            $profile, $assignment->phoneNumber, $recipient, $details, $cancelToken,
        );
    }

    public function sendOrderTypeChoices(WhatsAppTenantAssignment $assignment, string $recipient, string $body, array $choices): ?array
    {
        $assignment->loadMissing(['profile', 'phoneNumber']);
        $profile = $assignment->profile;
        if (! $profile || $profile->provider !== 'nexmsg'
            || ($profile->tenant_id !== null && (int) $profile->tenant_id !== (int) $assignment->tenant_id)) {
            return null;
        }
        $provider = $this->providers->make('nexmsg');

        return $provider instanceof NexMsgOrderingProvider
            ? $provider->sendOrderTypeChoices($profile, $assignment->phoneNumber, $recipient, $body, $choices)
            : null;
    }

    public function sendLocationRequest(WhatsAppTenantAssignment $assignment, string $recipient, string $body): ?array
    {
        $assignment->loadMissing(['profile', 'phoneNumber']);
        $profile = $assignment->profile;
        if (! $profile || $profile->provider !== 'nexmsg'
            || ($profile->tenant_id !== null && (int) $profile->tenant_id !== (int) $assignment->tenant_id)) return null;
        $provider = $this->providers->make('nexmsg');
        return $provider instanceof NexMsgOrderingProvider
            ? $provider->sendLocationRequest($profile, $assignment->phoneNumber, $recipient, $body) : null;
    }

    public function sendAddressActions(WhatsAppTenantAssignment $assignment, string $recipient, string $body, array $actions): ?array
    {
        $assignment->loadMissing(['profile', 'phoneNumber']);
        $profile = $assignment->profile;
        if (! $profile || $profile->provider !== 'nexmsg'
            || ($profile->tenant_id !== null && (int) $profile->tenant_id !== (int) $assignment->tenant_id)) return null;
        $provider = $this->providers->make('nexmsg');
        return $provider instanceof NexMsgOrderingProvider
            ? $provider->sendAddressActions($profile, $assignment->phoneNumber, $recipient, $body, $actions) : null;
    }

    public function sendCatalog(
        WhatsAppTenantAssignment $assignment,
        string $recipient,
        string $bodyText,
        string $footerText,
        ?string $operationId = null,
    ): array {
        $assignment->loadMissing(['profile', 'phoneNumber']);
        $profile = $assignment->profile;
        abort_unless($profile && ($profile->tenant_id === null || (int) $profile->tenant_id === (int) $assignment->tenant_id), 404);
        $provider = $this->providers->make($profile->provider);
        if (! $provider instanceof SendsWhatsAppCatalog) {
            throw new RuntimeException('The WhatsApp provider does not support catalog delivery.');
        }

        return $provider->sendCatalog($profile, $assignment->phoneNumber, $recipient, $bodyText, $footerText, $operationId);
    }
}
