<?php

namespace Modules\WhatsAppCenter\Services;

use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Saas\Models\Tenant;
use Modules\WhatsAppCenter\Data\WhatsAppChannelContext;
use Modules\WhatsAppCenter\Exceptions\WhatsAppContextException;
use Modules\WhatsAppCenter\Models\WhatsAppProviderProfile;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;

class WhatsAppChannelContextResolver
{
    public function resolveProfile(string $profileKey): WhatsAppProviderProfile
    {
        $profile = WhatsAppProviderProfile::query()->withoutGlobalTenant()
            ->where('is_active', true)
            ->where(function ($query) use ($profileKey): void {
                $query->where('uuid', $profileKey);
                if (ctype_digit($profileKey)) {
                    $query->orWhere($query->getModel()->getQualifiedKeyName(), (int) $profileKey);
                }
            })->first();

        if (! $profile || in_array($profile->status, ['disabled', 'suspended', 'revoked'], true)) {
            throw new WhatsAppContextException('WHATSAPP_INTEGRATION_DISABLED');
        }

        return $profile;
    }

    public function resolveProfileForProviderPhone(string $provider, string $providerPhoneId): WhatsAppProviderProfile
    {
        if (! in_array($provider, ['meta', 'msg91', 'nexmsg'], true) || $providerPhoneId === '') {
            throw new WhatsAppContextException('WHATSAPP_ACCOUNT_NOT_FOUND');
        }

        $profiles = WhatsAppProviderProfile::query()->withoutGlobalTenant()
            ->where('provider', $provider)
            ->where('is_active', true)
            ->whereNotIn('status', ['disabled', 'suspended', 'revoked'])
            ->whereHas('phoneNumbers', fn ($numbers) => $numbers
                ->where('provider_phone_id', $providerPhoneId)
                ->where('is_active', true))
            ->limit(2)
            ->get();

        if ($profiles->count() !== 1) {
            throw new WhatsAppContextException(
                $profiles->isEmpty() ? 'WHATSAPP_ACCOUNT_NOT_FOUND' : 'WHATSAPP_ACCOUNT_AMBIGUOUS',
                $profiles->isEmpty() ? 404 : 409,
            );
        }

        return $profiles->first();
    }

    public function resolve(string $profileKey, string $providerPhoneId, ?string $correlationId = null): WhatsAppChannelContext
    {
        $profile = $this->resolveProfile($profileKey);

        $number = $profile->phoneNumbers()->where('provider_phone_id', $providerPhoneId)
            ->where('is_active', true)->first();
        if (! $number) {
            throw new WhatsAppContextException('WHATSAPP_ACCOUNT_NOT_FOUND');
        }

        $assignment = WhatsAppTenantAssignment::query()->withoutGlobalTenant()
            ->where('provider_profile_id', $profile->id)
            ->where('phone_number_id', $number->id)
            ->where('is_active', true)->whereNull('suspended_at')->first();
        if (! $assignment || ($profile->tenant_id !== null && (int) $profile->tenant_id !== (int) $assignment->tenant_id)) {
            throw new WhatsAppContextException('WHATSAPP_ASSIGNMENT_INVALID');
        }
        $capabilities = $assignment->capabilities ?? [];
        $orderingEnabled = data_get($capabilities, 'ordering') === true || in_array('ordering', $capabilities, true);
        if (! $orderingEnabled) {
            throw new WhatsAppContextException('WHATSAPP_INTEGRATION_DISABLED', 403);
        }

        $tenant = Tenant::query()->whereKey($assignment->tenant_id)->where('is_active', true)->first();
        if (! $tenant) {
            throw new WhatsAppContextException('WHATSAPP_TENANT_DISABLED');
        }

        $branchQuery = Branch::query()->withoutGlobalActive()
            ->where('tenant_id', $assignment->tenant_id)->where('is_active', true);
        $configuredBranchIds = collect($assignment->allowed_branch_ids ?? [])->map(fn ($id) => (int) $id)
            ->filter()->unique()->values();
        $allowedBranchIds = (clone $branchQuery)
            ->when($configuredBranchIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $configuredBranchIds))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($allowedBranchIds === [] || ($configuredBranchIds->isNotEmpty() && count($allowedBranchIds) !== $configuredBranchIds->count())) {
            throw new WhatsAppContextException('WHATSAPP_BRANCH_UNAVAILABLE');
        }

        return new WhatsAppChannelContext(
            tenantId: (int) $assignment->tenant_id,
            branchId: count($allowedBranchIds) === 1 ? $allowedBranchIds[0] : null,
            providerProfileId: (int) $profile->id,
            phoneNumberId: (int) $number->id,
            assignmentId: (int) $assignment->id,
            assignmentUuid: (string) $assignment->uuid,
            channel: 'whatsapp',
            provider: (string) $profile->provider,
            allowedBranchIds: $allowedBranchIds,
            capabilities: $capabilities,
            correlationId: $correlationId ?: (string) Str::uuid(),
            profile: $profile,
            assignment: $assignment,
        );
    }
}
