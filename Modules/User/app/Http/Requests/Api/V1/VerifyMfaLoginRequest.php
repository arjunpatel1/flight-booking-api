<?php

namespace Modules\User\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class VerifyMfaLoginRequest extends Request
{
    public function rules(): array
    {
        return [
            'challenge_token' => ['required', 'string'],
            'code' => ['required', 'string', 'max:32'],
        ];
    }

    protected function availableAttributes(): string
    {
        return 'user::attributes.auth';
    }
}
