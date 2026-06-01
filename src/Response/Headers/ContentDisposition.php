<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

/**
 * Create a standards-compliant Content-Disposition value.
 * Uses RFC 5987 (filename*) with an ASCII quoted fallback.
 */
final class ContentDisposition
{
    /**
     * Generates a Content-Disposition header value for attachment download.
     *
     * @param string $filename The filename to include in the header.
     * @return string The formatted Content-Disposition header value.
     */
    public static function attachment(string $filename): string
    {
        return self::build('attachment', $filename);
    }

    /**
     * Generates a Content-Disposition header value for inline display.
     *
     * @param string $filename The filename to include in the header.
     * @return string The formatted Content-Disposition header value.
     */
    public static function inline(string $filename): string
    {
        return self::build('inline', $filename);
    }

    /**
     * Builds a standards-compliant Content-Disposition header value.
     * Uses RFC 5987 for UTF-8 filenames and provides an ASCII fallback.
     *
     * @param string $type The disposition type ('inline' or 'attachment').
     * @param string $filename The filename to include in the header.
     * @return string The formatted Content-Disposition header value.
     */
    private static function build(string $type, string $filename): string
    {
        // ASCII printable fallback; escape only quotes + backslashes for the quoted-string
        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $filename);
        $fallback = addcslashes((string) $fallback, '"\\');  // -> "safe\"name"

        $rfc5987 = rawurlencode($filename);         // full UTF-8 name

        return "{$type}; filename=\"{$fallback}\"; filename*=UTF-8''{$rfc5987}";
    }
}
