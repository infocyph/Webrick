<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Interop\Psr7;

use Infocyph\Webrick\Interfaces\BodyStream;
use Psr\Http\Message\StreamInterface;

/** Optional PSR stream boundary used only when psr/http-message is installed. */
final readonly class PsrBodyStreamAdapter implements BodyStream
{
    public function __construct(private StreamInterface $stream) {}

    public function __toString(): string
    {
        return $this->stream->__toString();
    }

    public function close(): void
    {
        $this->stream->close();
    }

    public function detach(): mixed
    {
        return $this->stream->detach();
    }

    public function eof(): bool
    {
        return $this->stream->eof();
    }

    public function getContents(): string
    {
        return $this->stream->getContents();
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $this->stream->getMetadata($key);
    }

    public function getSize(): ?int
    {
        return $this->stream->getSize();
    }

    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    public function isSeekable(): bool
    {
        return $this->stream->isSeekable();
    }

    public function isWritable(): bool
    {
        return $this->stream->isWritable();
    }

    public function read(int $length): string
    {
        return $this->stream->read($length);
    }

    public function rewind(): void
    {
        $this->stream->rewind();
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
    }

    public function tell(): int
    {
        return $this->stream->tell();
    }

    public function write(string $string): int
    {
        return $this->stream->write($string);
    }
}
