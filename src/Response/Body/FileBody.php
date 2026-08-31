<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Body;

use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Core\Stream;
use RuntimeException;
use Throwable;

/** Read-only file body preserving native path/range metadata for runtime adapters. */
final class FileBody implements BodyStream
{
    private readonly int $length;

    private int $position = 0;

    private ?Stream $stream = null;

    public function __construct(
        private readonly string $path,
        private readonly int $offset = 0,
        ?int $length = null,
    ) {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Unreadable response file: {$path}");
        }
        if ($offset < 0 || $length !== null && $length < 0) {
            throw new \InvalidArgumentException('File body offset/length must be non-negative.');
        }

        $size = filesize($path);
        if ($size === false || $offset > $size) {
            throw new RuntimeException("Unable to resolve response file size: {$path}");
        }

        $available = $size - $offset;
        if ($length !== null && $length > $available) {
            throw new \InvalidArgumentException('File body range exceeds file size.');
        }
        $this->length = $length ?? $available;
    }

    public function __toString(): string
    {
        try {
            $position = $this->position;
            $this->rewind();
            $contents = $this->getContents();
            $this->seek($position);

            return $contents;
        } catch (Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        $this->stream?->close();
        $this->stream = null;
    }

    public function detach(): mixed
    {
        $stream = $this->stream();
        $stream->seek($this->offset + $this->position);

        return $stream->detach();
    }

    public function eof(): bool
    {
        return $this->position >= $this->length;
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $chunk = $this->read(min(65_536, $this->length - $this->position));
            if ($chunk === '') {
                break;
            }
            $contents .= $chunk;
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $this->stream()->getMetadata($key);
    }

    public function getSize(): int
    {
        return $this->length;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function length(): int
    {
        return $this->length;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new RuntimeException('Read length cannot be negative.');
        }
        if ($length === 0 || $this->eof()) {
            return '';
        }

        $stream = $this->stream();
        $stream->seek($this->offset + $this->position);
        $chunk = $stream->read(min($length, $this->length - $this->position));
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
            throw new RuntimeException('Cannot seek outside the file body range.');
        }

        $this->position = $target;
        if ($this->stream !== null) {
            $this->stream->seek($this->offset + $target);
        }
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('FileBody is read-only.');
    }

    private function stream(): Stream
    {
        if ($this->stream !== null) {
            return $this->stream;
        }

        $handle = fopen($this->path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException("Unable to open response file: {$this->path}");
        }

        $this->stream = new Stream($handle);
        $this->stream->seek($this->offset + $this->position);

        return $this->stream;
    }
}
