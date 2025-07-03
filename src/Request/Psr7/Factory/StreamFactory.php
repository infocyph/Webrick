<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Request\Psr7\Factory;

use Infocyph\Webrick\Request\Core\Stream;
use Psr\Http\Message\{StreamFactoryInterface, StreamInterface};
use RuntimeException;

final class StreamFactory implements StreamFactoryInterface
{
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
}
