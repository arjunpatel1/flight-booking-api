<?php

namespace Modules\Aggregator\Services\PartnerApi;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Aggregator\Models\PartnerApiCredential;
use Modules\Aggregator\Models\PartnerApiIntegration;

class PartnerCredentialService
{
    public function createIntegration(int $tenantId, array $data): array
    {
        return DB::transaction(function () use ($tenantId, $data) {
            $partner = PartnerApiIntegration::query()->create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'name' => $data['name'],
                'environment' => $data['environment'] ?? 'production',
                'status' => 'active',
                'metadata' => $data['metadata'] ?? null,
            ]);
            [$credential, $secret] = $this->issue($partner, $data);

            return ['partner' => $partner, 'credential' => $credential, 'secret' => $secret];
        });
    }

    public function rotate(PartnerApiCredential $current): array
    {
        return DB::transaction(function () use ($current) {
            $current = PartnerApiCredential::query()->lockForUpdate()->findOrFail($current->id);
            $current->update([
                'status' => 'rotating',
                'grace_expires_at' => now()->addMinutes((int) config('aggregator.partner.credential_grace_minutes', 30)),
            ]);
            [$credential, $secret] = $this->issue($current->partner, [
                'scopes' => $current->scopes,
                'branch_ids' => $current->branch_ids,
                'ip_allowlist' => $current->ip_allowlist,
                'requests_per_minute' => $current->requests_per_minute,
                'orders_per_minute' => $current->orders_per_minute,
                'burst_limit' => $current->burst_limit,
                // Rotation must not silently break an already deployed client.
                'signature_version' => $current->signature_version,
                'rotated_from_id' => $current->id,
            ]);

            return ['credential' => $credential, 'secret' => $secret];
        });
    }

    private function issue(PartnerApiIntegration $partner, array $data): array
    {
        $scopes = array_values(array_unique($data['scopes'] ?? ['catalog:read', 'orders:read', 'orders:write']));
        if (in_array('orders:write', $scopes, true) && ! in_array('orders:read', $scopes, true)) {
            $scopes[] = 'orders:read';
        }

        $secret = $this->base64Url(random_bytes(48));
        $prefix = $partner->environment === 'sandbox' ? 'test' : 'live';
        $credential = PartnerApiCredential::query()->create([
            'uuid' => (string) Str::uuid(),
            'partner_id' => $partner->id,
            'tenant_id' => $partner->tenant_id,
            'api_key' => 'ndp_'.$prefix.'_'.$this->base64Url(random_bytes(24)),
            'secret_fingerprint' => hash('sha256', $secret),
            'secret_ciphertext' => $secret,
            'scopes' => $scopes,
            'branch_ids' => $data['branch_ids'] ?? null,
            'ip_allowlist' => $data['ip_allowlist'] ?? null,
            'requests_per_minute' => $data['requests_per_minute'] ?? 120,
            'orders_per_minute' => $data['orders_per_minute'] ?? 30,
            'burst_limit' => $data['burst_limit'] ?? 30,
            'signature_version' => $data['signature_version'] ?? 2,
            'status' => 'active',
            'rotated_from_id' => $data['rotated_from_id'] ?? null,
        ]);

        return [$credential, $secret];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
