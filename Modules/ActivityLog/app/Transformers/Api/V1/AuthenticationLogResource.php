<?php

namespace Modules\ActivityLog\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ActivityLog\Models\AuthenticationLog;
use Modules\User\Transformers\Api\V1\UserSimpleResource;

/** @mixin AuthenticationLog */
class AuthenticationLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $agent = $this->agent;
        return [
            "id" => $this->id,
            "ip_address" => $this->ip_address,
            "agent" => [
                'desktop' => $agent['is_desktop'],
                'platform' => $agent['platform'] ?: "-",
                'browser' => $agent['browser'] ?: "-",
            ],
            "login_at" => $this->login_at ? dateTimeFormat($this->login_at) : "-",
            "logout_at" => $this->logout_at ? dateTimeFormat($this->logout_at) : "-",
            "user" => UserSimpleResource::make($this->whenLoaded('authenticatable')),
        ];
    }
}
