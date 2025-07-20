<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Factory;

use Infocyph\Webrick\Request\Core\Stream;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

final class StreamFactory implements StreamFactoryInterface
{
    public function createStream(string $content = ''): StreamInterface
    {
        return new Stream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        $fp = fopen($filename, $mode);
        if (!$fp) {
            throw new \RuntimeException("Cannot open file: {$filename}");
        }
        return new Stream($fp);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return new Stream($resource);
    }
}
