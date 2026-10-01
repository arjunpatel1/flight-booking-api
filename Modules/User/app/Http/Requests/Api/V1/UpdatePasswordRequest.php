<?php

namespace Modules\User\Http\Requests\Api\V1;

use Illuminate\Validation\Rules\Password;
use Modules\Core\Http\Requests\Request;

class UpdatePasswordRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'current_password' => "required|string",
            "password" => [
                'required',
                'string',
                Password::min(8)
                    ->max(20)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
                "confirmed"
            ],
            "logout_from_other_devices" => "required|boolean",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "user::attributes.users";
    }
}
