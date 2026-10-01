<?php

namespace Modules\User\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class ForgotPasswordRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        // No "exists" check — the endpoint intentionally does not reveal whether
        // an email is registered.
        return [
            "email" => "required|email",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "user::attributes.auth";
    }
}
