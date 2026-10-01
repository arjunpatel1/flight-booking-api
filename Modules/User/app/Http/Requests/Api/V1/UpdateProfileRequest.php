<?php

namespace Modules\User\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;
use Modules\User\Http\Requests\Api\V1\Concerns\ValidatesTenantScopedUserIdentity;

class UpdateProfileRequest extends Request
{
    use ValidatesTenantScopedUserIdentity;

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $userId = auth()->id();

        return [
            "name" => "required|string|max:255",
            "username" => ['bail', 'required', 'string', 'max:255', $this->uniqueActiveUserValue('username')],
            "email" => ['bail', 'required', 'email:rfc', 'max:50', $this->uniqueActiveUserValue('email')],
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "user::attributes.users";
    }
}
