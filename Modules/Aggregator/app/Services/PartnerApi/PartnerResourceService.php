<?php

namespace Modules\Aggregator\Services\PartnerApi;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Aggregator\Models\PartnerApiResourceMapping;
use Modules\Aggregator\Support\PartnerContext;

class PartnerResourceService
{
    public function externalId(PartnerContext $context, string $type, Model $resource): string
    {
        $mapping = PartnerApiResourceMapping::query()->firstOrCreate(
            [
                'partner_id' => $context->partner->id,
                'resource_type' => $type,
                'resource_id' => $resource->getKey(),
            ],
            [
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $context->tenantId(),
                'external_reference' => (string) Str::uuid(),
            ],
        );

        return $mapping->external_reference;
    }

    public function internalId(PartnerContext $context, string $type, string $externalId): ?int
    {
        return PartnerApiResourceMapping::query()
            ->where('partner_id', $context->partner->id)
            ->where('tenant_id', $context->tenantId())
            ->where('resource_type', $type)
            ->where('external_reference', $externalId)
            ->value('resource_id');
    }
}
