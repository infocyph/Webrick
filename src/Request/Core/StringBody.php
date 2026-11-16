<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Core;

use Infocyph\Webrick\Interfaces\BodyStream;
use RuntimeException;

/**
 * In-memory string-backed body with stream-compatible cursor semantics.
 * No PHP stream resource or php://temp allocation is used.
 */
final class StringBody implements BodyStream
{
    private bool $closed = false;

    private int $position = 0;

    public function __construct(private string $value = '') {}

    public function __toString(): string
    {
        return $this->closed ? '' : $this->value;
    }

    #[\Override]
    public function close(): void
    {
        $this->closed = true;
        $this->value = '';
        $this->position = 0;
    }

    #[\Override]
    public function detach(): mixed
    {
        $this->close();

        return null;
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->closed || $this->position >= strlen($this->value);
    }

    #[\Override]
    public function getContents(): string
    {
        $this->assertOpen();
        $contents = substr($this->value, $this->position);
        $this->position = strlen($this->value);

        return $contents;
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        $metadata = [
            'stream_type' => 'webrick-string',
            'mode' => 'r+',
            'unread_bytes' => max(0, strlen($this->value) - $this->position),
            'seekable' => !$this->closed,
            'uri' => 'webrick://string',
        ];

        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }

    #[\Override]
    public function getSize(): ?int
    {
        return $this->closed ? null : strlen($this->value);
    }

    #[\Override]
    public function isReadable(): bool
    {
        return !$this->closed;
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return !$this->closed;
    }

    #[\Override]
    public function isWritable(): bool
    {
        return !$this->closed;
    }

    #[\Override]
    public function read(int $length): string
    {
        $this->assertOpen();
        if ($length < 0) {
            throw new RuntimeException('Read length must be >= 0');
        }
        if ($length === 0) {
            return '';
        }

        $chunk = substr($this->value, $this->position, $length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    #[\Override]
    public function rewind(): void
    {
        $this->seek(0);
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->assertOpen();
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen($this->value) + $offset,
            default => throw new RuntimeException('Invalid seek mode'),
        };
        if ($target < 0) {
            throw new RuntimeException('Stream seek failed');
        }
        $this->position = $target;
    }

    #[\Override]
    public function tell(): int
    {
        $this->assertOpen();

        return $this->position;
    }

    #[\Override]
    public function write(string $string): int
    {
        $this->assertOpen();
        $length = strlen($this->value);
        if ($this->position > $length) {
            $this->value .= str_repeat("\0", $this->position - $length);
        }

        $before = substr($this->value, 0, $this->position);
        $afterOffset = $this->position + strlen($string);
        $after = $afterOffset < strlen($this->value) ? substr($this->value, $afterOffset) : '';
        $this->value = $before . $string . $after;
        $this->position += strlen($string);

        return strlen($string);
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new RuntimeException('Stream detached');
        }
    }
}
