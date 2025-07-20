<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Factory;

use Infocyph\Webrick\Interfaces\ResponseFactoryInterface;   // ← the new umbrella
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Response\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class ResponseFactory implements ResponseFactoryInterface
{
    /* -----------------------------------------------------------------
       PSR-17 ResponseFactoryInterface
       ---------------------------------------------------------------- */
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        // empty body keeps memory low; caller can replace via withBody()
        return new Response($code, new Stream(), [], '1.1', $reasonPhrase);
    }

    /* -----------------------------------------------------------------
       PSR-17 StreamFactoryInterface
       ---------------------------------------------------------------- */
    public function createStream(string $contents = ''): StreamInterface
    {
        return new Stream($contents);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        $handle = @fopen($filename, $mode);
        if ($handle === false) {
            throw new RuntimeException("Unable to open file: {$filename}");
        }
        return new Stream($handle);
    }

    /** @param resource $resource */
    public function createStreamFromResource($resource): StreamInterface
    {
        if (!\is_resource($resource)) {
            throw new RuntimeException('createStreamFromResource() expects a valid resource');
        }
        return new Stream($resource);
    }
}
