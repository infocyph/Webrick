<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Factory;

use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Response\Response;
use RuntimeException;

final class ResponseFactory
{
    /**
     * Creates a new Response object.
     *
     * The body of the response will be empty (new Stream()), the headers will be empty,
     * the protocol version will be '1.1', and the reason phrase will be $reasonPhrase if
     * provided, or the corresponding HTTP status code reason phrase otherwise.
     *
     * @param int $code The HTTP status code.
     * @param string $reasonPhrase The reason phrase for the status code.
     * @return Response A new Response object.
     */
    public function createResponse(int $code = 200, string $reasonPhrase = ''): Response
    {
        // empty body keeps memory low; caller can replace via withBody()
        return new Response($code, new Stream(), [], '1.1', $reasonPhrase);
    }
    
    /**
     * Creates a new Stream object from a string.
     *
     * @param string $contents The string to create a Stream from.
     * @return Stream A new Stream object.
     */
    public function createStream(string $contents = ''): Stream
    {
        return new Stream($contents);
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
        $handle = @fopen($filename, $mode);
        if ($handle === false) {
            throw new RuntimeException("Unable to open file: {$filename}");
        }
        return new Stream($handle);
    }
    
    /**
     * Creates a new Stream object from a given resource.
     *
     * @param resource $resource A valid PHP resource.
     * @return Stream A new Stream object.
     * @throws RuntimeException If the given resource is invalid.
     */
    public function createStreamFromResource($resource): Stream
    {
        if (!\is_resource($resource)) {
            throw new RuntimeException('createStreamFromResource() expects a valid resource');
        }
        return new Stream($resource);
    }
}
