<?php

namespace Modules\Saas\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Saas\Models\CustomerAppContentItem;

class CustomerAppContentIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(CustomerAppContentItem::types())],
            'status' => ['nullable', Rule::in(CustomerAppContentItem::statuses())],
            'search' => ['nullable', 'string', 'max:120'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function filters(): array
    {
        return $this->validated();
    }
}
