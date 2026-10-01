<?php

namespace Modules\Product\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;

class SaveProductFavoriteRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id,deleted_at,NULL'],
            'product_id' => ['required', 'integer', 'exists:products,id,deleted_at,NULL'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id,deleted_at,NULL'],
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "product::attributes.product_favorites";
    }
}
