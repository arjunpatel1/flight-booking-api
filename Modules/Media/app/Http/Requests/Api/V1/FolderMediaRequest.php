<?php

namespace Modules\Media\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class FolderMediaRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            "folder_name" => "required|max:200",
            "folder_id" => "bail|nullable|numeric:|exists:media,id,created_by,$user->id",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "media::attributes.folders";
    }
}
