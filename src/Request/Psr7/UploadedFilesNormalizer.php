<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Psr7;

use Infocyph\Webrick\Request\Core\UploadedFile;

final class UploadedFilesNormalizer
{
    /**
     * @param array<string, mixed> $spec
     * @return array<string, UploadedFile|array<mixed>>
     */
    public static function normalise(array $spec): array
    {
        if ($spec === []) {
            return [];
        }

        $out = [];
        foreach ($spec as $name => $part) {
            $normalized = self::normalisePart($part);
            if ($normalized === null) {
                continue;
            }

            $out[$name] = $normalized;
        }

        return $out;
    }

    /**
     * @param array<int|string, mixed> $part
     */
    private static function leafFile(array $part, mixed $tmpName): UploadedFile
    {
        $size = isset($part['size']) && is_int($part['size']) ? $part['size'] : null;
        $error = isset($part['error']) && is_int($part['error']) ? $part['error'] : 0;
        $clientName = isset($part['name']) && is_string($part['name']) ? $part['name'] : null;
        $clientType = isset($part['type']) && is_string($part['type']) ? $part['type'] : null;

        return new UploadedFile(
            is_string($tmpName) ? $tmpName : '',
            $size,
            $error,
            $clientName,
            $clientType,
        );
    }

    /**
     * @param array{
     *   tmp_name: mixed,
     *   size: mixed,
     *   error: mixed,
     *   name: mixed,
     *   type: mixed
     * } $spec
     * @return array{
     *   tmp_name: array<int|string, mixed>,
     *   size: array<int|string, mixed>,
     *   error: array<int|string, mixed>,
     *   name: array<int|string, mixed>,
     *   type: array<int|string, mixed>
     * }|null
     */
    private static function nestedBag(array $spec): ?array
    {
        if (
            !is_array($spec['tmp_name'])
            || !is_array($spec['size'])
            || !is_array($spec['error'])
            || !is_array($spec['name'])
            || !is_array($spec['type'])
        ) {
            return null;
        }

        return [
            'tmp_name' => $spec['tmp_name'],
            'size' => $spec['size'],
            'error' => $spec['error'],
            'name' => $spec['name'],
            'type' => $spec['type'],
        ];
    }

    /**
     * @param array<int|string, mixed> $part
     * @param array<int|string, mixed> $tmpName
     * @return array{
     *   tmp_name: array<int|string, mixed>,
     *   size: array<int|string, mixed>,
     *   error: array<int|string, mixed>,
     *   name: array<int|string, mixed>,
     *   type: array<int|string, mixed>
     * }|null
     */
    private static function nestedInputBag(array $part, array $tmpName): ?array
    {
        $sizeBag = $part['size'] ?? null;
        $errorBag = $part['error'] ?? null;
        $nameBag = $part['name'] ?? null;
        $typeBag = $part['type'] ?? null;
        if (!is_array($sizeBag) || !is_array($errorBag) || !is_array($nameBag) || !is_array($typeBag)) {
            return null;
        }

        return [
            'tmp_name' => $tmpName,
            'size' => $sizeBag,
            'error' => $errorBag,
            'name' => $nameBag,
            'type' => $typeBag,
        ];
    }

    /**
     * @return UploadedFile|array<mixed>|null
     */
    private static function normalisePart(mixed $part): UploadedFile|array|null
    {
        if ($part instanceof UploadedFile) {
            return $part;
        }

        if (!is_array($part)) {
            return null;
        }

        $tmpName = $part['tmp_name'] ?? '';
        if (is_array($tmpName)) {
            $nested = self::nestedInputBag($part, $tmpName);

            return $nested === null ? null : self::unwindNestedFiles($nested);
        }

        return self::leafFile($part, $tmpName);
    }

    /**
     * @param array{
     *   tmp_name?: array<int|string, mixed>,
     *   size?: array<int|string, mixed>,
     *   error?: array<int|string, mixed>,
     *   name?: array<int|string, mixed>,
     *   type?: array<int|string, mixed>
     * } $bag
     * @return array<mixed, UploadedFile|array<mixed>>
     */
    private static function unwindNestedFiles(array $bag): array
    {
        $out = [];
        $tmpNames = $bag['tmp_name'] ?? [];
        $sizes = $bag['size'] ?? [];
        $errors = $bag['error'] ?? [];
        $names = $bag['name'] ?? [];
        $types = $bag['type'] ?? [];

        foreach ($tmpNames as $idx => $_) {
            $spec = [
                'tmp_name' => $tmpNames[$idx] ?? '',
                'size' => $sizes[$idx] ?? null,
                'error' => $errors[$idx] ?? null,
                'name' => $names[$idx] ?? null,
                'type' => $types[$idx] ?? null,
            ];
            if (is_array($spec['tmp_name'])) {
                $nested = self::nestedBag($spec);
                if ($nested === null) {
                    continue;
                }
                $out[$idx] = self::unwindNestedFiles($nested);

                continue;
            }

            $out[$idx] = UploadedFile::fromSpec(self::uploadedFileSpec($spec));
        }

        return $out;
    }

    /**
     * @param array{
     *   tmp_name: mixed,
     *   size: mixed,
     *   error: mixed,
     *   name: mixed,
     *   type: mixed
     * } $spec
     * @return array{
     *   tmp_name?: string,
     *   size?: int|null,
     *   error?: int,
     *   name?: string|null,
     *   type?: string|null
     * }
     */
    private static function uploadedFileSpec(array $spec): array
    {
        return [
            'tmp_name' => is_string($spec['tmp_name']) ? $spec['tmp_name'] : '',
            'size' => is_int($spec['size']) ? $spec['size'] : null,
            'error' => is_int($spec['error']) ? $spec['error'] : 0,
            'name' => is_string($spec['name']) ? $spec['name'] : null,
            'type' => is_string($spec['type']) ? $spec['type'] : null,
        ];
    }
}
