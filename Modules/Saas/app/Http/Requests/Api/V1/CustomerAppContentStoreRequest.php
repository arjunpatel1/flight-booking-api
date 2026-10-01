<?php

namespace Modules\Saas\Http\Requests\Api\V1;

class CustomerAppContentStoreRequest extends CustomerAppContentRequest
{
    protected function isCreating(): bool
    {
        return true;
    }
}
