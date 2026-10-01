<?php

namespace Modules\Menu\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

/**
 * @property-read string|null $slug
 */
class SaveOnlineMenuRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getTranslationRules([
                "name" => "required|string|max:255",
            ]),
            ...$this->getBranchRule(),
            "hotel_branch_id" => "nullable|numeric|exists:hotels,id",
            "menu_id" => "bail|nullable|numeric|exists:menus,id,deleted_at,NULL",
            "slug" => "bail|required|string|max:255|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/|unique:online_menus,slug,{$this->route('id')}",
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
        return "menu::attributes.online_menus";
    }
}
