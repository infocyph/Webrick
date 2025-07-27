<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Factory;

use Infocyph\Webrick\Request\Core\Stream;

final class StreamFactory
{
    public function createStream(string $content = ''): Stream
    {
        return new Stream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): Stream
    {
        $fp = fopen($filename, $mode);
        if (!$fp) {
            throw new \RuntimeException("Cannot open file: {$filename}");
        }
        return new Stream($fp);
    }

    public function createStreamFromResource($resource): Stream
    {
        return new Stream($resource);
    }
}
