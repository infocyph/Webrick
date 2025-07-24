<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Core;

use RuntimeException;
use SplFileObject;

/**
 * Tiny, allocation-aware PSR-7 stream.
 *
 *  • Accepts:  string payload · fopen() handle · SplFileObject · another StreamInterface
 *  • Determines readable / writable flags once in the ctor (cheap bit-test)
 *  • Never buffers entire file unless you explicitly cast to string
 *  • All operations throw RuntimeException on error – *never* return false
 */
final class Stream
{
    /** Verified PHP stream handle (resource|null after detach/close) */
    private mixed $h;

    private bool $readable;
    private bool $writable;

    /* ─────────────────────────── ctor ─────────────────────────── */

    public function __construct(mixed $source = '')
    {
        $this->h = match (true) {
            is_string($source) => self::openMemory($source),
            $source instanceof SplFileObject => self::openFileObject($source),
            $source instanceof Stream => $source->detach(),
            is_resource($source) => $source,
            default => throw new RuntimeException('Invalid stream source'),
        };

        $mode = stream_get_meta_data($this->h)['mode'];
        $this->readable = strpbrk($mode, 'r+') !== false;
        $this->writable = strpbrk($mode, 'waxc+') !== false;
    }

    /* ──────────── static open helpers – single responsibility ──────────── */

    private static function openMemory(string $payload): mixed
    {
        $h = fopen('php://temp', 'r+');
        if ($payload !== '') {
            fwrite($h, $payload);
            rewind($h);
        }
        return $h;
    }

    private static function openFileObject(SplFileObject $f): mixed
    {
        if (!$f->isReadable()) {
            throw new RuntimeException('File not readable: ' . $f->getPathname());
        }
        $h = fopen($f->getRealPath() ?: $f->getPathname(), $f->isWritable() ? 'r+' : 'r');
        if (!$h) {
            throw new RuntimeException('Unable to open file: ' . $f->getPathname());
        }
        return $h;
    }

    /* ────────────────── StreamInterface implementation ────────────────── */

    public function __toString(): string
    {
        if (!$this->h) {
            return '';
        }
        $pos = $this->tell();         // save cursor
        $this->rewind();
        $data = stream_get_contents($this->h) ?: '';
        $this->seek($pos);            // restore cursor
        return $data;
    }

    public function close(): void
    {
        if ($this->h) {
            fclose($this->h);
        }
        $this->h = null;
    }

    public function detach(): mixed
    {
        $h = $this->h;
        $this->h = null;
        return $h;
    }

    public function getSize(): ?int
    {
        return $this->h ? (fstat($this->h)['size'] ?? null) : null;
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
        return !$this->h || feof($this->h);
    }

    public function isSeekable(): bool
    {
        return $this->h ? (stream_get_meta_data($this->h)['seekable'] ?? false) : false;
    }

    public function seek($o, $w = SEEK_SET): void
    {
        $this->doSeek($o, $w);
    }

    public function rewind(): void
    {
        $this->doSeek(0);
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function write($s): int
    {
        $bytes = fwrite($this->need(), $s);
        if ($bytes === false) {
            throw new RuntimeException('Stream write failed');
        }
        return $bytes;
    }

    public function isReadable(): bool
    {
        return $this->readable;
    }

    public function read($l): string
    {
        $data = fread($this->need(), $l);
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
        if (!$this->h) {
            return $key ? null : [];
        }
        $meta = stream_get_meta_data($this->h);
        return $key ? ($meta[$key] ?? null) : $meta;
    }

    /* ───────────────────────── internal helpers ───────────────────────── */

    private function need(): mixed
    {
        return $this->h ?? throw new RuntimeException('Stream detached');
    }

    private function doSeek(int $off, int $whence = SEEK_SET): void
    {
        if (fseek($this->need(), $off, $whence) !== 0) {
            throw new RuntimeException('Stream seek failed');
        }
    }
}
