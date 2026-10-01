<?php

namespace Modules\WhatsAppCenter\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class WhatsAppConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $phone = (string) $this->customer_phone;
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        $customer = $digits === '' ? null : User::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenant_id)
            ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') = ?", [$digits])
            ->whereHas('roles', fn ($query) => $query->where('name', DefaultRole::Customer->value))
            ->first(['id', 'uuid', 'name']);
        $displayName = trim((string) $customer?->name)
            ?: trim((string) $this->customer_name)
            ?: 'WhatsApp Customer';

        return [
            'uuid' => $this->uuid,
            'branch' => $this->branch ? ['uuid' => $this->branch->uuid, 'name' => $this->branch->name] : null,
            'customer_name' => $displayName,
            'customer' => $customer ? ['uuid' => $customer->uuid, 'name' => $customer->name] : null,
            'customer_phone_masked' => strlen($phone) > 4 ? str_repeat('*', max(0, strlen($phone) - 4)).substr($phone, -4) : $phone,
            'state' => $this->state,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'messages' => WhatsAppMessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
