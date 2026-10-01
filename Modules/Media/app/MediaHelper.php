<?php

namespace Modules\Media;

class MediaHelper
{
    /**
     * The max file size
     *
     * @var int
     */
    public static int $maxFileSize = 30720;

    /**
     * Resolve preview image for the given extension.
     *
     * @param string|null $extension
     * @return string
     */
    public static function resolvePreviewImage(?string $extension = null): string
    {
        return url('/assets/media/' . ($extension ?? 'folder') . '.png');
    }
}
