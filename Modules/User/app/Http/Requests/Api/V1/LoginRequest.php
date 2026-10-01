<?php

namespace Modules\User\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class LoginRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            "identifier" => "required|string",
            "password" => "required|string|max:20",
            "remember" => "nullable|boolean",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "user::attributes.auth";
    }
}
