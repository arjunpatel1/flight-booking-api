<?php

namespace Modules\User\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\User\Models\Role;

/** @mixin Role */
class RoleShowResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $mutable = !$this->isBuiltIn() || ($actor && !$actor->assignedToTenant() && !$actor->assignedToBranch() && $actor->can('admin.saas.manage'));
        return [
            'can_update' => $mutable,
            'built_in' => $this->isBuiltIn(),
            ...(new RoleResource($this))->toArray($request),
            "permissions" => $this->getPermissionNames()->toArray(),
        ];
    }
}
