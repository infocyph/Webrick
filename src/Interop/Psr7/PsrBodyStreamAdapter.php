<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Interop\Psr7;

use Infocyph\Webrick\Interfaces\BodyStream;
use RuntimeException;

/** Optional duck-typed PSR stream boundary without a core psr/http-message dependency. */
final readonly class PsrBodyStreamAdapter implements BodyStream
{
    public function __construct(private object $stream) {}

    public function __toString(): string
    {
        return (string) $this->stream;
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
        return (bool) $this->stream->eof();
    }

    public function getContents(): string
    {
        $contents = $this->stream->getContents();
        if (!is_string($contents)) {
            throw new RuntimeException('PSR stream getContents() must return string.');
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $this->stream->getMetadata($key);
    }

    public function getSize(): ?int
    {
        $size = $this->stream->getSize();

        return is_int($size) ? $size : null;
    }

    public function isReadable(): bool
    {
        return (bool) $this->stream->isReadable();
    }

    public function isSeekable(): bool
    {
        return (bool) $this->stream->isSeekable();
    }

    public function isWritable(): bool
    {
        return (bool) $this->stream->isWritable();
    }

    public function read(int $length): string
    {
        $data = $this->stream->read($length);
        if (!is_string($data)) {
            throw new RuntimeException('PSR stream read() must return string.');
        }

        return $data;
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
        $position = $this->stream->tell();
        if (!is_int($position)) {
            throw new RuntimeException('PSR stream tell() must return int.');
        }

        return $position;
    }

    public function write(string $string): int
    {
        $written = $this->stream->write($string);
        if (!is_int($written)) {
            throw new RuntimeException('PSR stream write() must return int.');
        }

        return $written;
    }
}
