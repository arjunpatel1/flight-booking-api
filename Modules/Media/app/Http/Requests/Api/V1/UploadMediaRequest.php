<?php

namespace Modules\Media\Http\Requests\Api\V1;

use Closure;
use Illuminate\Http\UploadedFile;
use Modules\Core\Http\Requests\Request;
use Modules\Media\Enum\MediaMime;
use Modules\Media\Enum\MediaType;
use Modules\Media\MediaHelper;

class UploadMediaRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $user = $this->user();
        $maxFileSize = $this->maxUploadSizeInKilobytes();

        return [
            'file' => [
                'required',
                'file',
                'max:' . $maxFileSize,
                'mimes:' . MediaMime::forValidation(),
                function (string $attribute, mixed $value, Closure $fail) {
                    if (!$value instanceof UploadedFile) {
                        $fail('The :attribute must be a file.');
                        return;
                    }

                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $detectedMime = $finfo->file($value->getPathname());

                    if (!in_array($detectedMime, MediaMime::detectedMimeTypes(), true)) {
                        $fail('The :attribute has an invalid file type.');
                    }
                },
            ],
            'folder_id' => "bail|nullable|numeric|exists:media,id,created_by,{$user->id},type," . MediaType::Folder->value,
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'A file is required.',
            'file.file' => 'The uploaded file must be a valid file.',
            'file.max' => 'The file may not be greater than :max kilobytes.',
            'file.mimes' => 'The file must be one of the following types: :values.',
            'folder_id.exists' => 'The selected folder is invalid.',
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "media::attributes.upload";
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'file' => $this->file('file'),
        ]);
    }

    private function maxUploadSizeInKilobytes(): int
    {
        $phpLimit = $this->phpSizeToKilobytes((string) ini_get('upload_max_filesize'));

        return min($phpLimit, MediaHelper::$maxFileSize);
    }

    private function phpSizeToKilobytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return match ($unit) {
            'g' => (int) ($number * 1024 * 1024),
            'm' => (int) ($number * 1024),
            'k' => (int) $number,
            default => (int) ceil($number / 1024),
        };
    }

}
