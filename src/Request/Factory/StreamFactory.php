<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Factory;

use Infocyph\Webrick\Request\Stream;
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
        return new Stream(fopen($filename, $mode));
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return new Stream($resource);          // Stream ctor validates handle
    }
}
