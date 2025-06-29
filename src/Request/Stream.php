<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request;

use Psr\Http\Message\StreamInterface;
use RuntimeException;
use SplFileObject;

/**
 * Tiny PSR-7 stream implementation.
 *
 * Accepted constructor sources
 * ----------------------------
 *  • `string`            – in-memory temp stream is created
 *  • `SplFileObject`     – we reopen its pathname as a classic handle
 *  • `StreamInterface`   – its handle is detached and re-used
 *  • any PHP stream **handle** returned by fopen(), tmpfile(), etc.
 *
 * No `resource` type-hint is used; we only *detect* handles at runtime.
 */
final class Stream implements StreamInterface
{
    /** @var mixed  verified PHP stream handle */
    private mixed $handle;

    private bool $readable;
    private bool $writable;

    /**
     * @param mixed $source see class-level docblock
     */
    public function __construct(mixed $source = '')
    {
        $this->handle = match (true) {
            is_string($source)                 => self::openMemory($source),
            $source instanceof SplFileObject   => self::openFileObject($source),
            $source instanceof StreamInterface => $source->detach(),
            is_resource($source)               => $source,
            default                            => throw new RuntimeException('Invalid stream source'),
        };

        $meta           = stream_get_meta_data($this->handle);
        $mode           = $meta['mode'];
        $this->readable = strpbrk($mode, 'r+') !== false;
        $this->writable = strpbrk($mode, 'waxc+') !== false;
    }

    /* -----------------------------------------------------------------
     * Static helpers
     * ----------------------------------------------------------------- */

    private static function openMemory(string $payload): mixed
    {
        $h = fopen('php://temp', 'r+');
        if ($payload !== '') {
            fwrite($h, $payload);
            rewind($h);
        }
        return $h;
    }

    private static function openFileObject(SplFileObject $file): mixed
    {
        if (!$file->isReadable()) {
            throw new RuntimeException('File is not readable: ' . $file->getPathname());
        }
        $path = $file->getRealPath() ?: $file->getPathname();
        $h    = fopen($path, $file->isWritable() ? 'r+' : 'r');
        if (!$h) {
            throw new RuntimeException("Unable to open file: {$path}");
        }
        return $h;
    }

    /* -----------------------------------------------------------------
     * PSR-7 StreamInterface
     * ----------------------------------------------------------------- */

    public function __toString(): string
    {
        if (!is_resource($this->handle)) {
            return '';
        }
        $this->rewind();
        return stream_get_contents($this->handle) ?: '';
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
        $h           = $this->handle;
        $this->handle = null;
        return $h;
    }

    public function getSize(): ?int
    {
        return $this->stat('size');
    }

    public function tell(): int
    {
        $pos = ftell($this->need());
        if ($pos === false) {
            throw new RuntimeException('Unable to determine stream position');
        }
        return $pos;
    }

    public function eof(): bool
    {
        return !$this->handle || feof($this->handle);
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        if (fseek($this->need(), $offset, $whence) !== 0) {
            throw new RuntimeException('Stream seek failed');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function write($string): int
    {
        $bytes = fwrite($this->need(), $string);
        if ($bytes === false) {
            throw new RuntimeException('Stream write failed');
        }
        return $bytes;
    }

    public function isReadable(): bool
    {
        return $this->readable;
    }

    public function read($length): string
    {
        $data = fread($this->need(), $length);
        if ($data === false) {
            throw new RuntimeException('Stream read failed');
        }
        return $data;
    }

    public function getContents(): string
    {
        $data = stream_get_contents($this->need());
        if ($data === false) {
            throw new RuntimeException('Unable to read stream contents');
        }
        return $data;
    }

    public function getMetadata($key = null): mixed
    {
        if (!$this->handle) {
            return $key ? null : [];
        }
        $meta = stream_get_meta_data($this->handle);
        return $key ? ($meta[$key] ?? null) : $meta;
    }

    /* -----------------------------------------------------------------
     * Internal helpers
     * ----------------------------------------------------------------- */

    private function need(): mixed
    {
        return $this->handle ?? throw new RuntimeException('Stream detached');
    }

    private function stat(string $field): ?int
    {
        return $this->handle ? (fstat($this->handle)[$field] ?? null) : null;
    }
}
