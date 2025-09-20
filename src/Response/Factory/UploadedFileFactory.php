<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Factory;

use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Core\UploadedFile;

final class UploadedFileFactory
{
    /**
     * Creates an UploadedFile from a Stream, size, error code, client-provided filename and client-provided MIME type.
     *
     * @param Stream $stream The underlying Stream for the uploaded file.
     * @param int|null $size The size of the uploaded file in bytes (0 or null for auto).
     * @param int $error The error code for the uploaded file (UPLOAD_ERR_* constant).
     * @param string|null $clientFilename The client-provided filename for the uploaded file.
     * @param string|null $clientMediaType The client-provided MIME type for the uploaded file.
     * @return UploadedFile
     */
    public function createUploadedFile(
        Stream $stream,
        ?int $size = null,
        int $error = \UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null,
    ): UploadedFile {
        return new UploadedFile(
            $stream,
            $size,
            $error,
            $clientFilename,
            $clientMediaType,
        );
    }
}
