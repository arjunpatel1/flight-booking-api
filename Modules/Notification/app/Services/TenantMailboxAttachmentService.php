<?php

namespace Modules\Notification\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\Notification\Models\TenantMailAttachment;
use Modules\Notification\Models\TenantMailMessage;

class TenantMailboxAttachmentService
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'text/plain',
        'text/csv', 'application/csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function store(TenantMailMessage $message, UploadedFile $file): TenantMailAttachment
    {
        $disk = (string) config('filesystems.private_disk', 'local');
        $mime = Str::lower((string) ($file->getMimeType() ?: 'application/octet-stream'));
        $size = max(0, (int) $file->getSize());
        $accepted = $file->isValid() && $size > 0 && $size <= 10 * 1024 * 1024 && in_array($mime, self::ALLOWED_MIME_TYPES, true);
        $status = $accepted ? 'accepted' : 'quarantined';
        $directory = "tenant-mail/{$message->tenant_id}/{$status}/{$message->id}";
        $name = Str::uuid().'.'.Str::lower($file->guessExtension() ?: 'bin');
        $path = $file->storeAs($directory, $name, $disk);
        throw_if($path === false, new \RuntimeException('Unable to store private mail attachment.'));

        return $message->attachments()->create([
            'tenant_id' => $message->tenant_id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit(basename($file->getClientOriginalName()), 255, ''),
            'mime_type' => $mime,
            'size' => $size,
            'status' => $status,
            'quarantine_reason' => $accepted ? null : 'file_type_or_size_not_allowed',
        ]);
    }
}
