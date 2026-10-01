<?php

namespace Modules\User\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class ConfirmMfaRequest extends Request
{
    public function rules(): array
    {
        return ['code' => ['required', 'digits:6']];
    }

    protected function availableAttributes(): string
    {
        return 'user::attributes.auth';
    }
}
