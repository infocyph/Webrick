<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Factory;

use Infocyph\Webrick\Request\Core\Stream;

final class StreamFactory
{
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
     * Opens a file and returns a PSR-7 Stream for the opened file.
     *
     * @param string $filename The filename to open.
     * @param string $mode The mode to open the file in.
     * @return Stream A new Stream object representing the opened file.
     * @throws RuntimeException If the file cannot be opened.
     */
    public function createStreamFromFile(string $filename, string $mode = 'r'): Stream
    {
        $fp = fopen($filename, $mode);
        if (!$fp) {
            throw new \RuntimeException("Cannot open file: {$filename}");
        }
        return new Stream($fp);
    }

    /**
     * Creates a new Stream instance from a given resource.
     *
     * Accepts a string, a PSR-7 Stream, a PHP stream resource, or a SplFileObject.
     * If the given source is invalid, it will throw a RuntimeException.
     *
     * @param mixed $resource The resource to create a Stream from.
     * @return Stream A new Stream instance.
     * @throws RuntimeException If the given source is invalid.
     */
    public function createStreamFromResource($resource): Stream
    {
        return new Stream($resource);
    }
}
