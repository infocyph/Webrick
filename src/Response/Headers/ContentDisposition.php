<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

/**
 * Create a standards-compliant **Content-Disposition** value.
 *
 * Supports inline / attachment + RFC 5987 fallback filename*.
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
        // plain token fallback (ASCII only) + UTF-8 encoded copy
        $safe     = preg_replace('/[^0-9A-Za-z.\-_]/', '_', $filename);
        $encoded  = rawurlencode($filename);
        return "{$type}; filename=\"{$safe}\"; filename*=UTF-8''{$encoded}";
    }
}
