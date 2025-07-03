<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Factory;

use Infocyph\Webrick\Request\{Stream, UploadedFile};
use Psr\Http\Message\{StreamInterface, UploadedFileFactoryInterface, UploadedFileInterface};

final class UploadedFileFactory implements UploadedFileFactoryInterface
{
    public function createUploadedFile(
        StreamInterface $stream,
        int             $size    = null,
        int             $error   = \UPLOAD_ERR_OK,
        string          $clientFilename = null,
        string          $clientMediaType = null
    ): UploadedFileInterface {
        return new UploadedFile(
            $stream,
            $size,
            $error,
            $clientFilename,
            $clientMediaType
        );
    }
}
