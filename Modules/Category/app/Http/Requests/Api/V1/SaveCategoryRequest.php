<?php

namespace Modules\Category\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;

/**
 * @property-read int $menu_id
 * @property-read string|null $slug
 */
class SaveCategoryRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getTranslationRules(['name' => "required|string|max:255"]),
            ...$this->getMenuRule(),
            "slug" => [
                'bail',
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('categories', 'slug')
                    ->where('menu_id', $this->menu_id)
                    ->ignore($this->route('id')),
            ],
            "parent_id" => "bail|nullable|numeric|exists:categories,id,deleted_at,NULL,menu_id,$this->menu_id",
            "is_active" => "required|boolean",
        ];
    }

    /** @inheritDoc */
    public function validationData(): array
    {
        return [
            ...parent::validationData(),
            'slug' => $this->slug ? str($this->slug)->slug()->toString() : null,
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "category::attributes.categories";
    }
}
