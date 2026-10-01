<?php

namespace Modules\Media\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class UpdateMediaRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            "name" => "required|max:200",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "media::attributes.media";
    }
}
