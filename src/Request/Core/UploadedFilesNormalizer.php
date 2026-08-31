<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Core;

final class UploadedFilesNormalizer
{
    /**
     * @param array<string,mixed> $spec
     * @return array<string,UploadedFile|array<mixed>>
     */
    public static function normalise(array $spec): array
    {
        if ($spec === []) {
            return [];
        }

        $out = [];
        foreach ($spec as $name => $part) {
            $normalized = self::normalisePart($part);
            if ($normalized !== null) {
                $out[$name] = $normalized;
            }
        }

        return $out;
    }

    /** @param array<int|string,mixed> $part */
    private static function leafFile(array $part, mixed $tmpName): UploadedFile
    {
        return new UploadedFile(
            is_string($tmpName) ? $tmpName : '',
            isset($part['size']) && is_int($part['size']) ? $part['size'] : null,
            isset($part['error']) && is_int($part['error']) ? $part['error'] : 0,
            isset($part['name']) && is_string($part['name']) ? $part['name'] : null,
            isset($part['type']) && is_string($part['type']) ? $part['type'] : null,
        );
    }

    /** @param array<int|string,mixed> $part */
    private static function normalisePart(mixed $part): UploadedFile|array|null
    {
        if ($part instanceof UploadedFile) {
            return $part;
        }
        if (!is_array($part)) {
            return null;
        }

        $tmpName = $part['tmp_name'] ?? '';
        if (!is_array($tmpName)) {
            return self::leafFile($part, $tmpName);
        }

        $size = $part['size'] ?? null;
        $error = $part['error'] ?? null;
        $name = $part['name'] ?? null;
        $type = $part['type'] ?? null;
        if (!is_array($size) || !is_array($error) || !is_array($name) || !is_array($type)) {
            return null;
        }

        return self::unwindNestedFiles([
            'tmp_name' => $tmpName,
            'size' => $size,
            'error' => $error,
            'name' => $name,
            'type' => $type,
        ]);
    }

    /**
     * @param array{tmp_name:array<int|string,mixed>,size:array<int|string,mixed>,error:array<int|string,mixed>,name:array<int|string,mixed>,type:array<int|string,mixed>} $bag
     * @return array<mixed,UploadedFile|array<mixed>>
     */
    private static function unwindNestedFiles(array $bag): array
    {
        $out = [];
        foreach ($bag['tmp_name'] as $idx => $tmpName) {
            $size = $bag['size'][$idx] ?? null;
            $error = $bag['error'][$idx] ?? null;
            $name = $bag['name'][$idx] ?? null;
            $type = $bag['type'][$idx] ?? null;

            if (is_array($tmpName)) {
                if (!is_array($size) || !is_array($error) || !is_array($name) || !is_array($type)) {
                    continue;
                }
                $out[$idx] = self::unwindNestedFiles([
                    'tmp_name' => $tmpName,
                    'size' => $size,
                    'error' => $error,
                    'name' => $name,
                    'type' => $type,
                ]);
                continue;
            }

            $out[$idx] = UploadedFile::fromSpec([
                'tmp_name' => is_string($tmpName) ? $tmpName : '',
                'size' => is_int($size) ? $size : null,
                'error' => is_int($error) ? $error : 0,
                'name' => is_string($name) ? $name : null,
                'type' => is_string($type) ? $type : null,
            ]);
        }

        return $out;
    }
}
