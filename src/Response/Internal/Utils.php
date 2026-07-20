<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Internal;

/**
 * Tiny reusable helpers – **no** globals.
 */
final class Utils
{
    /**
     * Generate a collision-resistant strong ETag from a string payload.
     *
     * - Produces a quoted 128-bit xxHash hexadecimal digest.
     *
     * @param string $payload Input string to hash
     * @return string Quoted ETag value
     */
    public static function generateEtag(string $payload): string
    {
        return '"' . hash('xxh128', $payload, false) . '"';
    }

    /**
     * Format a UNIX epoch as an RFC-7231 HTTP-date in GMT.
     *
     * - When $epoch is null the current time() is used.
     * - The returned string is suitable for Date/Expires/Last-Modified headers,
     *   e.g. "Tue, 15 Nov 1994 08:12:31 GMT".
     *
     * @param int|null $epoch UNIX epoch seconds or null to use current time
     * @return string RFC-7231 formatted date in GMT
     */
    public static function httpDate(?int $epoch = null): string
    {
        return gmdate('D, d M Y H:i:s', $epoch ?? time()) . ' GMT';
    }

    /**
     * @param array<mixed> $headers
     * @return array<string, string|list<string>>
     */
    public static function normalizeHeaderMap(array $headers, bool $wrapSingleValues = false): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (!\is_string($name)) {
                continue;
            }

            if (\is_string($value)) {
                $out[$name] = $wrapSingleValues ? [$value] : $value;

                continue;
            }

            if (!\is_array($value)) {
                continue;
            }

            $vals = [];
            foreach ($value as $item) {
                if (\is_string($item)) {
                    $vals[] = $item;
                }
            }

            $out[$name] = $vals;
        }

        return $out;
    }

    /**
     * @param array<mixed> $headers
     * @return array<string, list<string>>
     */
    public static function normalizeHeaderValueLists(array $headers): array
    {
        $normalized = self::normalizeHeaderMap($headers, true);

        return \array_map(
            static fn(string|array $value): array => \is_string($value) ? [$value] : $value,
            $normalized,
        );
    }
}
