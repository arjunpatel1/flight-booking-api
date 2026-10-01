<?php

namespace Modules\Media\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Media\Enum\MediaMime;
use Modules\Media\Enum\MediaType;
use Modules\Media\MediaHelper;
use Modules\Media\Models\Media;
use Modules\Saas\Support\TenantContext;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MediaService implements MediaServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("media::media.media");
    }

    /** @inheritDoc */
    public function get(array $filters = [], int $skip = 0): Collection
    {
        return $this->getModel()
            ->query()
            ->filters($filters)
            ->latest()
            ->take(30)
            ->skip($skip)
            ->get();
    }

    /** @inheritDoc */
    public function getModel(): Media
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Media::class;
    }

    /** @inheritDoc */
    public function store(UploadedFile $file, ?int $mediaId = null): ?Media
    {
        // Additional security checks
        $this->validateFileSecurity($file);

        // Images are public presentation assets. Documents and spreadsheets
        // may contain customer or financial data and must use private storage.
        $disk = str_starts_with((string) $file->getMimeType(), 'image/')
            ? config('media.disk', 'public')
            : config('media.private_disk', 'local');

        // Resolve the parent before writing bytes. An invalid or cross-tenant
        // folder id must not leave an orphaned file on disk.
        $parent = $mediaId ? Media::query()->findOrFail($mediaId) : null;
        $tenantId = $this->tenantId() ?? $parent?->tenant_id;
        $path = Storage::disk($disk)->putFile($this->tenantMediaDirectory($tenantId), $file);

        if ($path) {
            $object = [
                'tenant_id' => $tenantId,
                'disk' => $disk,
                'name' => $file->getClientOriginalName(),
                'path' => $path,
                'extension' => $file->guessExtension() ?? '',
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                "type" => MediaType::File
            ];

            if ($parent) {
                $media = new Media($object);
                $media->appendToNode($parent)->save();
            } else {
                $media = $this->getModel()->query()->create($object);
            }

            return $media;
        }

        return null;
    }

    /**
     * Validate file security
     */
    private function validateFileSecurity(UploadedFile $file): void
    {
        $allowedMimes = MediaMime::detectedMimeTypes();
        $fileMime = $file->getMimeType();
        
        if (!in_array($fileMime, $allowedMimes)) {
            $this->failUpload("Invalid file type: {$fileMime}.");
        }

        $allowedExtensions = MediaMime::values();
        $fileExtension = strtolower($file->getClientOriginalExtension());
        
        if (!in_array($fileExtension, $allowedExtensions)) {
            $this->failUpload("Invalid file extension: {$fileExtension}.");
        }

        $maxSize = MediaHelper::$maxFileSize * 1024;
        if ($file->getSize() > $maxSize) {
            $this->failUpload("File size exceeds maximum allowed size.");
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file->getPathname());
        
        if (!in_array($detectedMime, $allowedMimes)) {
            $this->failUpload("File content does not match an allowed file type.");
        }

        if (in_array($fileExtension, ['jpg', 'jpeg'])) {
            $this->sanitizeImage($file);
        }
    }

    private function failUpload(string $message): never
    {
        throw ValidationException::withMessages([
            'file' => [$message],
        ]);
    }

    private function tenantId(): ?int
    {
        $tenantId = app()->bound(TenantContext::class)
            ? app(TenantContext::class)->id()
            : null;

        $tenantId ??= auth()->check() ? auth()->user()?->tenant_id : null;

        return $tenantId ? (int) $tenantId : null;
    }

    private function tenantMediaDirectory(?int $tenantId): string
    {
        return $tenantId ? "tenants/{$tenantId}/media" : 'media';
    }

    /**
     * Sanitize image by removing EXIF data
     */
    private function sanitizeImage(UploadedFile $file): void
    {
        try {
            if (!function_exists('imagecreatefromjpeg') || !function_exists('imagejpeg')) {
                return;
            }

            $image = imagecreatefromjpeg($file->getPathname());
            if ($image) {
                imagejpeg($image, $file->getPathname(), 90);
                imagedestroy($image);
            }
        } catch (Throwable $e) {
            \Log::warning('Failed to sanitize image', ['error' => $e->getMessage()]);
        }
    }

    /** @inheritDoc */
    public function update(int $id, string $name): Media
    {
        $media = $this->getModel()
            ->query()
            ->where("id", $id)
            ->firstOrFail();

        $media->update(["name" => $name]);

        return $media;
    }

    /** @inheritDoc */
    public function storeFolder(array $data): Media
    {
        $folderId = $data['folder_id'] ?? null;
        $parent = $folderId ? Media::query()->findOrFail($folderId) : null;

        $object = [
            'tenant_id' => $this->tenantId() ?? $parent?->tenant_id,
            'name' => $data['folder_name'],
            "type" => MediaType::Folder
        ];

        if ($parent) {
            $media = new Media($object);
            $media->appendToNode($parent)->save();
        } else {
            $media = $this->getModel()->query()->create($object);
        }
        
        return $media;
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        return count($this->getModel()->query()->find(parseIds($ids))->each->delete()) > 0;
    }

    /** @inheritDoc */
    public function downloadFile(int $id): StreamedResponse
    {
        $media = $this->getModel()
            ->query()
            ->where("id", $id)
            ->where("type", MediaType::File)
            ->firstOrFail();

        return $media->download();
    }
}
