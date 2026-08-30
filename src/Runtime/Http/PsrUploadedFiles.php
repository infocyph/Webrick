<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Infocyph\Webrick\Interop\Psr7\PsrBodyStreamAdapter;
use Infocyph\Webrick\Request\Core\UploadedFile;

final readonly class PsrUploadedFiles
{
    /** @return array<string,UploadedFile|array<mixed>> */
    public static function normalize(mixed $files): array
    {
        return is_array($files) ? self::map($files) : [];
    }

    /** @param array<mixed> $files @return array<string,UploadedFile|array<mixed>> */
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
        if (!is_object($file)) {
            return null;
        }

        $stream = $file->getStream();
        if (!is_object($stream)) {
            return null;
        }

        $size = $file->getSize();
        $error = $file->getError();
        $name = $file->getClientFilename();
        $type = $file->getClientMediaType();

        return new UploadedFile(
            new PsrBodyStreamAdapter($stream),
            is_int($size) ? $size : null,
            is_int($error) ? $error : UPLOAD_ERR_NO_FILE,
            is_string($name) ? $name : null,
            is_string($type) ? $type : null,
        );
    }
}
