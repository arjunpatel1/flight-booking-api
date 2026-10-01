<?php

namespace Modules\WhatsAppCenter\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Branch\Models\Branch;

class WhatsAppConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $credentials = is_array($this->profile?->credentials) ? $this->profile->credentials : [];
        $branchIds = collect($this->allowed_branch_ids ?? [])->map(fn ($id) => (int) $id)->filter();
        $branches = Branch::query()->withoutGlobalActive()->where('tenant_id', $this->tenant_id)
            ->when($branchIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $branchIds))
            ->orderBy('name')->get(['id', 'uuid', 'name']);

        return [
            'uuid' => $this->uuid,
            'ownership_mode' => $this->ownership_mode,
            'provider' => $this->profile?->provider,
            'profile_name' => $this->profile?->name,
            'provider_phone_id' => $this->phoneNumber?->provider_phone_id,
            'status' => $this->profile?->status,
            'display_number' => $this->phoneNumber?->display_number,
            'configuration' => [
                'business_account_id' => $credentials['business_account_id'] ?? null,
                'account_id' => $credentials['account_id'] ?? null,
                'catalog_id' => $credentials['catalog_id'] ?? null,
                'has_access_token' => filled($credentials['access_token'] ?? null),
                'has_auth_key' => filled($credentials['auth_key'] ?? null),
                'has_webhook_secret' => filled($credentials['webhook_secret'] ?? null),
            ],
            'branches' => $branches->map(fn ($branch) => ['uuid' => $branch->uuid, 'name' => $branch->name])->values(),
            'allowed_branch_ids' => $branches->pluck('uuid')->values(),
            'is_active' => (bool) $this->is_active,
            'capabilities' => $this->capabilities ?? [],
            'webhook' => [
                'status' => $this->profile?->status,
                'last_received_at' => $this->profile?->webhook_last_received_at?->toIso8601String(),
            ],
        ];
    }
}
