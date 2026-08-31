<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Infocyph\Webrick\Interop\Psr7\PsrBodyStreamAdapter;
use Infocyph\Webrick\Request\Core\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;

final readonly class PsrUploadedFiles
{
    /**
     * @return array<string,UploadedFile|array<array-key,mixed>>
     */
    public static function normalize(mixed $files): array
    {
        return is_array($files) ? self::map($files) : [];
    }

    /**
     * @param array<array-key,mixed> $files
     * @return array<string,UploadedFile|array<array-key,mixed>>
     */
    private static function map(array $files): array
    {
        $out = [];
        foreach ($files as $name => $file) {
            if (!is_string($name)) {
                continue;
            }
            $value = self::one($file);
            if ($value !== null) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * @return UploadedFile|array<array-key,mixed>|null
     */
    private static function one(mixed $file): UploadedFile|array|null
    {
        if (is_array($file)) {
            $out = [];
            foreach ($file as $key => $entry) {
                $value = self::one($entry);
                if ($value !== null) {
                    $out[$key] = $value;
                }
            }

            return $out;
        }
        if (!$file instanceof UploadedFileInterface) {
            return null;
        }

        return new UploadedFile(
            new PsrBodyStreamAdapter($file->getStream()),
            $file->getSize(),
            $file->getError(),
            $file->getClientFilename(),
            $file->getClientMediaType(),
        );
    }
}
