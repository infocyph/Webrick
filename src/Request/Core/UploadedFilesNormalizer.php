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
            is_int($part['size'] ?? null) ? $part['size'] : null,
            is_int($part['error'] ?? null) ? $part['error'] : 0,
            is_string($part['name'] ?? null) ? $part['name'] : null,
            is_string($part['type'] ?? null) ? $part['type'] : null,
        );
    }

    /**
     * @param array<int|string,mixed> $part
     * @param array<int|string,mixed> $tmpName
     * @return array<string,array<int|string,mixed>>|null
     */
    private static function nestedInputBag(array $part, array $tmpName): ?array
    {
        foreach (['size', 'error', 'name', 'type'] as $key) {
            if (!is_array($part[$key] ?? null)) {
                return null;
            }
        }

        return [
            'tmp_name' => $tmpName,
            'size' => $part['size'],
            'error' => $part['error'],
            'name' => $part['name'],
            'type' => $part['type'],
        ];
    }

    /** @return UploadedFile|array<mixed>|null */
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

        $nested = self::nestedInputBag($part, $tmpName);

        return $nested === null ? null : self::unwindNestedFiles($nested);
    }

    /**
     * @param array<string,array<int|string,mixed>> $bag
     * @return array<mixed,UploadedFile|array<mixed>>
     */
    private static function unwindNestedFiles(array $bag): array
    {
        $out = [];
        foreach ($bag['tmp_name'] ?? [] as $index => $tmpName) {
            $spec = [
                'tmp_name' => $tmpName,
                'size' => $bag['size'][$index] ?? null,
                'error' => $bag['error'][$index] ?? null,
                'name' => $bag['name'][$index] ?? null,
                'type' => $bag['type'][$index] ?? null,
            ];

            if (is_array($tmpName)) {
                $nested = self::nestedInputBag($spec, $tmpName);
                if ($nested !== null) {
                    $out[$index] = self::unwindNestedFiles($nested);
                }
                continue;
            }

            $out[$index] = UploadedFile::fromSpec([
                'tmp_name' => is_string($tmpName) ? $tmpName : '',
                'size' => is_int($spec['size']) ? $spec['size'] : null,
                'error' => is_int($spec['error']) ? $spec['error'] : 0,
                'name' => is_string($spec['name']) ? $spec['name'] : null,
                'type' => is_string($spec['type']) ? $spec['type'] : null,
            ]);
        }

        return $out;
    }
}
