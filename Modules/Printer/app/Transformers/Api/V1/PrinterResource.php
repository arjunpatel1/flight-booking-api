<?php

namespace Modules\Printer\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Printer\Enum\PrinterProviderType;
use Modules\Printer\Models\Printer;

/** @mixin Printer */
class PrinterResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "name" => $this->name,
            "branch" => [
                "id" => $this->branch_id,
                "name" => $this->relationLoaded("branch") ? $this->branch?->name : "",
            ],
            "connection_type" => $this->connection_type->toTrans(),
            "provider_type" => ($this->provider_type ?? PrinterProviderType::WindowsAgent)->toTrans(),
            "agent_id" => data_get($this->options, 'agent_id'),
            "connection_target" => data_get($this->options, 'spooler_name')
                ?: data_get($this->options, 'host')
                ?: data_get($this->options, 'device_path')
                ?: data_get($this->options, 'mac_address')
                ?: '-',
            "is_active" => $this->is_active,
            "updated_at" => dateTimeFormat($this->updated_at),
            "created_at" => dateTimeFormat($this->created_at),
        ];
    }
}
