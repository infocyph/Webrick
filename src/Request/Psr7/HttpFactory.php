<?php

namespace Infocyph\Webrick\Request\Psr7;

use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Core\UploadedFile;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use RuntimeException;

final class HttpFactory
{
    public function createRequest(string $method, $uri, array $serverParams = []): Request
    {
        return new Request($method, $uri instanceof Uri ? $uri : new Uri((string)$uri), $serverParams);
    }

    public function createStream(string $content = ''): Stream
    {
        return new Stream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): Stream
    {
        $h = @fopen($filename, $mode);
        if ($h === false) {
            throw new RuntimeException("Unable to open file: {$filename}");
        }
        return new Stream($h);
    }

    /** @param resource $resource */
    public function createStreamFromResource($resource): Stream
    {
        return new Stream($resource);
    }

    public function createUploadedFile(
        Stream $stream,
        ?int $size = null,
        int $error = \UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null,
    ): UploadedFile {
        return new UploadedFile($stream, $size, $error, $clientFilename, $clientMediaType);
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): Response
    {
        return new Response($code, new Stream(), [], '1.1', $reasonPhrase);
    }

    public function createUri(string $uri = ''): Uri
    {
        return new Uri($uri);
    }
}
