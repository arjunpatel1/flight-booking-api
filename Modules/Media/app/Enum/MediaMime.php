<?php

namespace Modules\Media\Enum;

use Modules\Support\Traits\EnumArrayable;

enum MediaMime: string
{
    use EnumArrayable;

    case Avi = 'avi';
    case Csv = 'csv';
    case Doc = "doc";
    case Docx = "docx";
    case Mov = 'mov';
    case Mp4 = 'mp4';
    case Mpeg = 'mpeg';
    case Pdf = 'pdf';
    case Ppt = "ppt";
    case Pptx = "pptx";
    case Rar = 'rar';
    case Txt = 'txt';
    case Xls = 'xls';
    case Xlsx = "xlsx";
    case Zip = "zip";
    case Png = "png";
    case Jpg = "jpg";
    case Jpeg = "jpeg";
    case Svg = "svg";
    case Webp = "webp";
    case Mp3 = "mp3";
    case Wav = "wav";
    case Ogg = "ogg";
    case flac = "flac";
    case Aac = "aac";
    case M4a = "m4a";
    case Wma = "wma";
    case Webm = "webm";


    /**
     * Get mime types for validation
     *
     * @return string
     */
    public static function forValidation(): string
    {
        return implode(',', MediaMime::values());
    }

    /**
     * MIME types accepted after server-side content detection.
     *
     * Laravel's mimes rule validates extensions, while this list validates the
     * detected file signature/content type so normal image uploads do not fail
     * and spoofed files do not pass as product media.
     *
     * @return array<int, string>
     */
    public static function detectedMimeTypes(): array
    {
        return [
            'application/msword',
            'application/pdf',
            'application/rtf',
            'application/vnd.ms-excel',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/x-rar',
            'application/x-rar-compressed',
            'application/zip',
            'audio/aac',
            'audio/flac',
            'audio/mpeg',
            'audio/ogg',
            'audio/wav',
            'audio/webm',
            'audio/x-m4a',
            'audio/x-ms-wma',
            'image/jpeg',
            'image/png',
            'image/svg+xml',
            'image/webp',
            'text/csv',
            'text/plain',
            'video/mp4',
            'video/mpeg',
            'video/quicktime',
            'video/webm',
            'video/x-msvideo',
        ];
    }
}
