<?php

/**
 * Webrick - HTTP helper utilities.
 *
 * Provides small, focused helpers for HTTP-related tasks, such as checking common
 * Content-Type values for classic HTML form submissions.
 *
 * @package Infocyph\Webrick\Support
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

/**
 * Utility helpers for HTTP concerns.
 *
 * This final class contains static convenience methods used throughout the
 * Webrick stack when dealing with HTTP headers and content types.
 */
final class HttpUtils
{
    /**
     * Determine if a Content-Type denotes a classic HTML form payload.
     *
     * Accepts both:
     * - application/x-www-form-urlencoded
     * - multipart/form-data
     *
     * The check is case-insensitive and ignores any parameters (e.g., boundary, charset).
     *
     * @param string $contentType The Content-Type header value, possibly with parameters.
     *
     * @return bool True if the content type is a classic form type; false otherwise.
     */
    public static function isFormContentType(string $contentType): bool
    {
        if ($contentType === '') {
            return false;
        }
        $mime = strtolower(strtok($contentType, ';') ?: '');
        return $mime === 'application/x-www-form-urlencoded'
            || $mime === 'multipart/form-data';
    }
}
