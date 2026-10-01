<?php

namespace Modules\Tracking\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class RejectLiveOrderRequest extends Request
{
    public function rules(): array
    {
        return [
            'reason_id' => 'bail|required|integer|exists:reasons,id,deleted_at,NULL,is_active,1,type,cancellation',
            'note' => 'nullable|string|max:1000',
        ];
    }

    protected function availableAttributes(): string
    {
        return 'order::attributes.reasons';
    }
}
