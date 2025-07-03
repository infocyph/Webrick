<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Request\Testing;

use Infocyph\Webrick\Request\Psr7\Factory\{
    ServerRequestFactory, StreamFactory, UploadedFileFactory, ResponseFactory, UriFactory
};
use Psr\Http\Message\{
    ResponseFactoryInterface, ResponseInterface,
    ServerRequestFactoryInterface, ServerRequestInterface,
    StreamFactoryInterface, StreamInterface,
    UploadedFileFactoryInterface, UploadedFileInterface,
    UriFactoryInterface, UriInterface
};

final class HttpFactory implements
    ServerRequestFactoryInterface,
    StreamFactoryInterface,
    UploadedFileFactoryInterface,
    ResponseFactoryInterface,
    UriFactoryInterface
{
    private ServerRequestFactory $srv;
    private StreamFactory        $stream;
    private UploadedFileFactory  $upload;
    private ResponseFactory      $resp;
    private UriFactory           $uri;

    public function __construct()
    {
        $this->srv    = new ServerRequestFactory();
        $this->stream = new StreamFactory();
        $this->upload = new UploadedFileFactory();
        $this->resp   = new ResponseFactory();
        $this->uri    = new UriFactory();
    }

    /* ----- ServerRequestFactoryInterface ---------------- */
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        return $this->srv->createServerRequest($method, $uri, $serverParams);
    }

    /* ----- StreamFactoryInterface ---------------------- */
    public function createStream(string $content = ''): StreamInterface
    {
        return $this->stream->createStream($content);
    }
    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return $this->stream->createStreamFromFile($filename, $mode);
    }
    public function createStreamFromResource($resource): StreamInterface
    {
        return $this->stream->createStreamFromResource($resource);
    }

    /* ----- UploadedFileFactoryInterface ---------------- */
    public function createUploadedFile(
        StreamInterface $stream,
        int $size = null,
        int $error = \UPLOAD_ERR_OK,
        string $clientFilename = null,
        string $clientMediaType = null
    ): UploadedFileInterface {
        return $this->upload->createUploadedFile($stream, $size, $error, $clientFilename, $clientMediaType);
    }

    /* ----- ResponseFactoryInterface -------------------- */
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return $this->resp->createResponse($code, $reasonPhrase);
    }

    /* ----- UriFactoryInterface ------------------------- */
    public function createUri(string $uri = ''): UriInterface
    {
        return $this->uri->createUri($uri);
    }
}
