<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Constants;

/**
 * Tiny lookup for common media-types.
 * Purpose: avoid huge libs just to map “json” → “application/json”.
 */
final class Mime
{
    private const MAP = [
        'html' => 'text/html; charset=utf-8',
        'json' => 'application/json',
        'xml'  => 'application/xml',
        'txt'  => 'text/plain; charset=utf-8',
        'csv'  => 'text/csv; charset=utf-8',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'pdf'  => 'application/pdf',
        // add more as needed – keep list small for opcode cache
    ];

    public static function fromExtension(string $ext, string $fallback = 'application/octet-stream'): string
    {
        return self::MAP[strtolower($ext)] ?? $fallback;
    }
}
