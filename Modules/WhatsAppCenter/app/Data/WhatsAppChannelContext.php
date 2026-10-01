<?php

namespace Modules\WhatsAppCenter\Data;

use Modules\WhatsAppCenter\Models\WhatsAppProviderProfile;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;

final readonly class WhatsAppChannelContext
{
    public function __construct(
        public int $tenantId,
        public ?int $branchId,
        public int $providerProfileId,
        public int $phoneNumberId,
        public int $assignmentId,
        public string $assignmentUuid,
        public string $channel,
        public string $provider,
        public array $allowedBranchIds,
        public array $capabilities,
        public string $correlationId,
        public WhatsAppProviderProfile $profile,
        public WhatsAppTenantAssignment $assignment,
    ) {}

    public function permitsBranch(int $branchId): bool
    {
        return in_array($branchId, $this->allowedBranchIds, true);
    }
}
