<?php

namespace Modules\Saas\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Saas\Models\CustomerAppContentItem;

abstract class CustomerAppContentRequest extends FormRequest
{
    public function rules(): array
    {
        $presence = $this->isCreating() ? 'required' : 'sometimes';

        return [
            'tenant_id' => ['prohibited'],
            'type' => [$presence, Rule::in(CustomerAppContentItem::types())],
            'title' => [$presence, 'string', 'max:160'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:2000'],
            'image_path' => ['nullable', 'string', 'max:2048'],
            'mobile_image_path' => ['nullable', 'string', 'max:2048'],
            'cta_action' => ['nullable', Rule::in(CustomerAppContentItem::ctaActions())],
            'cta_label' => ['nullable', 'string', 'max:80'],
            'cta_target' => ['nullable', 'string', 'max:2048'],
            'linked_resource_type' => ['nullable', Rule::in(['promotion', 'discount', 'menu', 'event', 'product'])],
            'linked_resource_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(CustomerAppContentItem::statuses())],
            'is_active' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'metadata' => ['nullable', 'array', 'max:50'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function payload(): array
    {
        return $this->validated();
    }

    protected function isCreating(): bool
    {
        return false;
    }
}
