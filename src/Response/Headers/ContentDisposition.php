<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

/**
 * Create a standards-compliant Content-Disposition value.
 * Uses RFC 5987 (filename*) with an ASCII quoted fallback.
 */
final class ContentDisposition
{
    public static function inline(string $filename): string
    {
        return self::build('inline', $filename);
    }

    public static function attachment(string $filename): string
    {
        return self::build('attachment', $filename);
    }

    private static function build(string $type, string $filename): string
    {
        // ASCII printable fallback; escape only quotes + backslashes for the quoted-string
        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $filename);
        $fallback = addcslashes($fallback, "\"\\");  // -> "safe\"name"

        $rfc5987  = rawurlencode($filename);         // full UTF-8 name

        return "{$type}; filename=\"{$fallback}\"; filename*=UTF-8''{$rfc5987}";
    }
}
