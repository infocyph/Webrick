<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Http;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Core\StringBody;
use Infocyph\Webrick\Request\Core\UploadedFile;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use RuntimeException;

/** Native Webrick HTTP-value factory; deliberately not advertised as PSR-17. */
final class HttpFactory
{
    /**
     * @param array<string,mixed> $serverParams
     * @param string $method
     * @param Uri|string $uri
     */
    public function createRequest(string $method, Uri|string $uri, array $serverParams = []): Request
    {
        return new Request(
            HttpMethodEnum::normalize($method),
            $uri instanceof Uri ? $uri : new Uri($uri),
            $serverParams,
        );
    }

    public function createResponse(int $code = StatusEnum::OK->value, string $reasonPhrase = ''): Response
    {
        if ($reasonPhrase === '' && ($status = StatusEnum::tryFrom($code))) {
            $reasonPhrase = $status->reason();
        }

        return new Response($code, '', [], '1.1', $reasonPhrase);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): Stream
    {
        $handle = fopen($filename, $mode);
        if ($handle === false) {
            throw new RuntimeException("Unable to open file: {$filename}");
        }

        return new Stream($handle);
    }

    /** @param resource $resource */
    public function createStreamFromResource($resource): Stream
    {
        if (!is_resource($resource)) {
            throw new RuntimeException('createStreamFromResource() expects a valid resource');
        }

        return new Stream($resource);
    }

    public function createStringBody(string $content = ''): StringBody
    {
        return new StringBody($content);
    }

    public function createUploadedFile(
        Stream $stream,
        ?int $size = null,
        int $error = UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null,
    ): UploadedFile {
        return new UploadedFile($stream, $size, $error, $clientFilename, $clientMediaType);
    }

    public function createUri(string $uri = ''): Uri
    {
        return new Uri($uri);
    }
}
