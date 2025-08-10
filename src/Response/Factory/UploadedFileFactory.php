<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Factory;

use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Core\UploadedFile;

final class UploadedFileFactory
{
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
