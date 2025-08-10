<?php

namespace Infocyph\Webrick\Response\Range;

use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Core\Stream;
use RuntimeException;

final class ByteRangeStream implements BodyStream
{
    private Stream $base;
    private int $remaining;

    public function __construct(Stream $base, int $limit)
    {
        $this->base      = $base;
        $this->remaining = $limit;
    }

    /* -------- PSR-7: forwarding with minimal overrides -------- */

    public function __toString(): string
    {
        $this->rewind();
        return $this->getContents();
    }

    public function close(): void
    {
        $this->base->close();
    }

    public function detach(): mixed
    {
        return $this->base->detach();
    }

    public function getSize(): ?int
    {
        return $this->remaining;
    }

    public function tell(): int
    {
        return $this->base->tell();
    }

    public function eof(): bool
    {
        return $this->remaining === 0 || $this->base->eof();
    }

    public function isSeekable(): bool
    {
        return $this->base->isSeekable();
    }

    public function isWritable(): bool
    {
        return false; // read-only
    }

    public function isReadable(): bool
    {
        return $this->base->isReadable();
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        if (!$this->isSeekable()) {
            throw new RuntimeException('Stream is not seek-able');
        }
        $this->base->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function write($string): int
    {
        throw new RuntimeException('ByteRangeStream is read-only');
    }

    public function read($length): string
    {
        if ($this->remaining === 0) {
            return '';
        }

        $length = min($length, $this->remaining);
        $chunk  = $this->base->read($length);

        $this->remaining -= strlen($chunk);
        return $chunk;
    }

    public function getContents(): string
    {
        $data = $this->base->getContents();
        $data = substr($data, 0, $this->remaining);
        $this->remaining -= strlen($data);
        return $data;
    }

    public function getMetadata($key = null): mixed
    {
        return $this->base->getMetadata($key);
    }
}
