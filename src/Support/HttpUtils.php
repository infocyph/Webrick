<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Support;

final class HttpUtils
{
    /** True when Content-Type is classic HTML form (urlencoded or multipart). */
    public static function isFormContentType(string $contentType): bool
    {
        $mime = strtolower(strtok($contentType, ';') ?: '');
        return $mime === 'application/x-www-form-urlencoded'
            || $mime === 'multipart/form-data';
    }
}
