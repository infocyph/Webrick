<?php

namespace Infocyph\Webrick\Request\Psr7;

use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Core\UploadedFile;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Response\Response;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

final class HttpFactory implements
    ServerRequestFactoryInterface,
    StreamFactoryInterface,
    UploadedFileFactoryInterface,
    ResponseFactoryInterface,
    UriFactoryInterface
{
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        return new ServerRequest($method, $uri instanceof UriInterface ? $uri : new Uri((string)$uri), $serverParams);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return new Stream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        $h = @fopen($filename, $mode);
        if ($h === false) {
            throw new RuntimeException("Unable to open file: {$filename}");
        }
        return new Stream($h);
    }

    /** @param resource $resource */
    public function createStreamFromResource($resource): StreamInterface
    {
        return new Stream($resource);
    }

    public function createUploadedFile(
        StreamInterface $stream,
        ?int $size = null,
        int $error = \UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null,
    ): UploadedFileInterface {
        /** @var UploadedFileInterface */
        return new UploadedFile($stream, $size, $error, $clientFilename, $clientMediaType);
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response($code, new Stream(), [], '1.1', $reasonPhrase);
    }

    public function createUri(string $uri = ''): UriInterface
    {
        return new Uri($uri);
    }
}

