<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Core;

use Infocyph\Webrick\Interfaces\BodyStream;
use RuntimeException;
use SplFileObject;

/**
 * Resource-backed body stream.
 *
 * Native string bodies use StringBody; this class remains the explicit
 * resource/file boundary. String input is retained as a compatibility path.
 */
final class Stream implements BodyStream
{
    private readonly bool $readable;
    private readonly bool $writable;

    /** @var resource|null */
    private $handle;

    public function __construct(mixed $source = '')
    {
        $handle = match (true) {
            is_string($source) => self::openMemory($source),
            $source instanceof SplFileObject => self::openFileObject($source),
            $source instanceof self => $source->detach(),
            is_resource($source) => $source,
            default => throw new RuntimeException('Invalid stream source'),
        };
        if (!is_resource($handle)) {
            throw new RuntimeException('Invalid stream handle');
        }

        $this->handle = $handle;
        $metadata = stream_get_meta_data($handle);
        $mode = is_string($metadata['mode'] ?? null) ? $metadata['mode'] : '';
        $this->readable = strpbrk($mode, 'r+') !== false;
        $this->writable = strpbrk($mode, 'waxc+') !== false;
    }

    public function __toString(): string
    {
        $handle = $this->handle;
        if (!is_resource($handle) || !$this->readable) {
            return '';
        }

        // Non-seekable streams cannot preserve/restore a cursor. Return the
        // remaining bytes from the current position instead of failing on rewind.
        if (!$this->isSeekable()) {
            $data = stream_get_contents($handle);
            return $data === false ? '' : $data;
        }

        $position = ftell($handle);
        if ($position === false || fseek($handle, 0, SEEK_SET) !== 0) {
            return '';
        }

        try {
            $data = stream_get_contents($handle);
            return $data === false ? '' : $data;
        } finally {
            fseek($handle, $position, SEEK_SET);
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function detach(): mixed
    {
        $handle = $this->handle;
        $this->handle = null;

        return $handle;
    }

    public function eof(): bool
    {
        return !is_resource($this->handle) || feof($this->handle);
    }

    public function getContents(): string
    {
        if (!$this->readable) {
            throw new RuntimeException('Stream not readable');
        }
        $data = stream_get_contents($this->need());
        if ($data === false) {
            throw new RuntimeException('Unable to read stream contents');
        }

        return $data;
    }

    public function getMetadata(?string $key = null): mixed
    {
        if (!is_resource($this->handle)) {
            return $key === null ? [] : null;
        }
        $metadata = stream_get_meta_data($this->handle);

        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }

    public function getSize(): ?int
    {
        if (!is_resource($this->handle)) {
            return null;
        }
        $stat = fstat($this->handle);

        return is_array($stat) && is_int($stat['size'] ?? null) ? $stat['size'] : null;
    }

    public function isReadable(): bool
    {
        return $this->readable && is_resource($this->handle);
    }

    public function isSeekable(): bool
    {
        if (!is_resource($this->handle)) {
            return false;
        }
        $metadata = stream_get_meta_data($this->handle);

        return ($metadata['seekable'] ?? false) === true;
    }

    public function isWritable(): bool
    {
        return $this->writable && is_resource($this->handle);
    }

    public function read(int $length): string
    {
        if (!$this->readable) {
            throw new RuntimeException('Stream not readable');
        }
        if ($length < 0) {
            throw new RuntimeException('Read length must be >= 0');
        }
        if ($length === 0) {
            return '';
        }

        $data = fread($this->need(), $length);
        if ($data === false) {
            throw new RuntimeException('Stream read failed');
        }

        return $data;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (!$this->isSeekable() || fseek($this->need(), $offset, $whence) !== 0) {
            throw new RuntimeException('Stream seek failed');
        }
    }

    public function tell(): int
    {
        $position = ftell($this->need());
        if ($position === false) {
            throw new RuntimeException('Unable to determine stream position');
        }

        return $position;
    }

    public function write(string $string): int
    {
        if (!$this->writable) {
            throw new RuntimeException('Stream not writable');
        }
        $bytes = fwrite($this->need(), $string);
        if ($bytes === false) {
            throw new RuntimeException('Stream write failed');
        }

        return $bytes;
    }

    /** @return resource */
    private static function openFileObject(SplFileObject $file)
    {
        if (!$file->isReadable()) {
            throw new RuntimeException('File not readable: ' . $file->getPathname());
        }
        $handle = fopen($file->getRealPath() ?: $file->getPathname(), $file->isWritable() ? 'r+' : 'r');
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to open file: ' . $file->getPathname());
        }

        return $handle;
    }

    /** @return resource */
    private static function openMemory(string $payload)
    {
        $handle = fopen('php://temp', 'r+');
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to open temporary stream');
        }
        if ($payload !== '') {
            if (fwrite($handle, $payload) === false || rewind($handle) === false) {
                fclose($handle);
                throw new RuntimeException('Unable to initialize temporary stream');
            }
        }

        return $handle;
    }

    /** @return resource */
    private function need()
    {
        return is_resource($this->handle) ? $this->handle : throw new RuntimeException('Stream detached');
    }
}
