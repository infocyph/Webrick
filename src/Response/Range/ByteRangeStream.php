<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Range;

use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Core\Stream;
use RuntimeException;
use Throwable;

/**
 * Read-only seekable window over a base stream.
 */
final class ByteRangeStream implements BodyStream
{
    private int $position = 0;

    public function __construct(
        private readonly Stream $base,
        private readonly int $start,
        private readonly int $length,
    ) {
        if ($start < 0 || $length < 0) {
            throw new \InvalidArgumentException('Byte range start/length must be non-negative.');
        }
        if (!$base->isSeekable()) {
            throw new RuntimeException('ByteRangeStream requires a seekable base stream.');
        }

        $size = $base->getSize();
        if ($size !== null && ($start > $size || $length > $size - $start)) {
            throw new \InvalidArgumentException('Byte range window exceeds the base stream size.');
        }

        $this->base->seek($this->start);
    }

    public function __toString(): string
    {
        $position = $this->position;
        try {
            $this->rewind();

            return $this->getContents();
        } catch (Throwable) {
            return '';
        } finally {
            try {
                $this->seek($position);
            } catch (Throwable) {
                // String conversion must not throw because position restoration failed.
            }
        }
    }

    public function close(): void
    {
        $this->base->close();
    }

    public function detach(): mixed
    {
        return $this->base->detach();
    }

    public function eof(): bool
    {
        return $this->position >= $this->length || $this->base->eof();
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $chunk = $this->read(min(8_192, $this->length - $this->position));
            if ($chunk === '') {
                break;
            }
            $contents .= $chunk;
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $this->base->getMetadata($key);
    }

    public function getSize(): int
    {
        return $this->length;
    }

    public function isReadable(): bool
    {
        return $this->base->isReadable();
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new RuntimeException('Read length cannot be negative.');
        }
        if ($length === 0 || $this->eof()) {
            return '';
        }

        $chunk = $this->base->read(min($length, $this->length - $this->position));
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $this->length + $offset,
            default => throw new RuntimeException('Invalid seek origin.'),
        };

        if ($target < 0 || $target > $this->length) {
            throw new RuntimeException('Cannot seek outside the byte-range window.');
        }

        $this->base->seek($this->start + $target, SEEK_SET);
        $this->position = $target;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('ByteRangeStream is read-only.');
    }
}
