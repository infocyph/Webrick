<?php

namespace Infocyph\Webrick\Request\Psr7;

use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Core\UploadedFile;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use RuntimeException;

final class HttpFactory
{
    /**
     * Creates a new Request object from a method, URI and server parameters.
     *
     * @param string $method The HTTP method (e.g. GET, POST, PUT, DELETE).
     * @param Uri|string $uri The URI of the request as a string or Uri object.
     * @param array $serverParams The server parameters, typically from $_SERVER.
     * @return Request A new Request object.
     */
    public function createRequest(string $method, $uri, array $serverParams = []): Request
    {
        return new Request($method, $uri instanceof Uri ? $uri : new Uri((string)$uri), $serverParams);
    }

    /**
     * Creates a new Stream object from a string.
     *
     * @param string $content The string to create a Stream from.
     * @return Stream A new Stream object.
     */
    public function createStream(string $content = ''): Stream
    {
        return new Stream($content);
    }

    /**
     * Creates a new Stream object from a file.
     *
     * @param string $filename The filename to open.
     * @param string $mode The mode to open the file in.
     * @return Stream A new Stream object.
     * @throws RuntimeException If the file cannot be opened.
     */
    public function createStreamFromFile(string $filename, string $mode = 'r'): Stream
    {
        $h = @fopen($filename, $mode);
        if ($h === false) {
            throw new RuntimeException("Unable to open file: {$filename}");
        }
        return new Stream($h);
    }

    /**
     * Creates a new Stream object from a given resource.
     *
     * @param resource $resource The resource to read from.
     * @return Stream A new Stream object.
     */
    public function createStreamFromResource($resource): Stream
    {
        return new Stream($resource);
    }

    /**
     * Creates a new UploadedFile instance.
     *
     * @param Stream $stream The underlying stream for the uploaded file.
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
        return new UploadedFile($stream, $size, $error, $clientFilename, $clientMediaType);
    }

    /**
     * Creates a new Response object.
     *
     * If no reason phrase is provided, it will use the corresponding status code to
     * determine the reason phrase.
     *
     * @param int $code The HTTP status code.
     * @param string $reasonPhrase The reason phrase for the status code.
     * @return Response A new Response object.
     */
    public function createResponse(int $code = 200, string $reasonPhrase = ''): Response
    {
        if ($reasonPhrase === '' && ($st = StatusEnum::tryFrom($code))) {
            $reasonPhrase = $st->reason();
        }
        return new Response($code, new Stream(), [], '1.1', $reasonPhrase);
    }

    /**
     * Creates a new Uri object from a given string or empty object.
     *
     * If the string is empty, it will create an empty Uri object.
     * Otherwise, it will parse the string and compute all the properties
     * needed for the Uri object.
     *
     * @param string $uri The string to parse into a Uri object.
     * @return Uri A new Uri object.
     */
    public function createUri(string $uri = ''): Uri
    {
        return new Uri($uri);
    }
}
