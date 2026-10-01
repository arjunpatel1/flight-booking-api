<?php

namespace Modules\Saas\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use LogicException;

class CustomerAppMediaUploadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:4096'],
            'slot' => ['nullable', Rule::in(['image', 'mobile_image'])],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function mediaFile(): UploadedFile
    {
        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            throw new LogicException('Validated customer app media file is missing.');
        }

        return $file;
    }
}
