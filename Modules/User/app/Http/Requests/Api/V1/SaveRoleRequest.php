<?php

namespace Modules\User\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\User\Support\RolePermissionBoundary;

/**
 * @property int|string|null $id
 */
class SaveRoleRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getTranslationRules(["display_name" => "required|string|max:255"]),
            "permissions" => "nullable|array",
            "permissions.*" => ["required", Rule::in(app(RolePermissionBoundary::class)->allowedNames())]
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "user::attributes.roles";
    }
}
