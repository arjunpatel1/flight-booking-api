<?php

namespace Modules\User\Http\Requests\Api\V1;

use Illuminate\Validation\Rules\Password;
use Modules\Core\Http\Requests\Request;

class ResetPasswordRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            "token" => "required|string",
            "email" => "required|email",
            "password" => [
                "required",
                "confirmed",
                Password::min(8)->max(20)->mixedCase()->numbers()->symbols(),
            ],
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "user::attributes.auth";
    }
}
