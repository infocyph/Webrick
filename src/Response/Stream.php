<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/** Lightweight PSR-7 Stream (read-seek-write capable). */
final class Stream implements StreamInterface
{
    /** @var resource|null */
    private $resource;
    private bool $seekable;
    private bool $readable;
    private bool $writable;

    public function __construct(mixed $body = '')
    {
        /* ---------------------------------------------------------
         * 1. Existing StreamInterface instance? → use directly
         * ------------------------------------------------------- */
        if ($body instanceof StreamInterface) {
            $this->resource = $body->detach();   // reuse its handle
        }
        /* 2. Raw PHP resource? (fopen, tmpfile, etc.) -------------- */
        elseif (is_resource($body)) {
            $this->resource = $body;
        }
        /* 3. Anything else → treat as string payload ---------------- */
        else {
            $this->resource = fopen('php://temp', 'r+');
            if ($body !== '') {
                fwrite($this->resource, (string)$body);
                rewind($this->resource);
            }
        }

        $meta            = stream_get_meta_data($this->resource);
        $mode            = $meta['mode'];
        $this->seekable  = $meta['seekable'];
        $this->readable  = strpbrk($mode, 'r+') !== false;
        $this->writable  = strpbrk($mode, 'waxc+') !== false;
    }

    public function __destruct() { $this->close(); }

    /* ------------------------------------------------ StreamInterface */

    public function __toString(): string
    {
        if (!$this->resource) { return ''; }
        $this->seekable && rewind($this->resource);
        return stream_get_contents($this->resource) ?: '';
    }

    public function close(): void
    {
        if ($this->resource) { fclose($this->resource); }
        $this->resource = null;
    }

    public function detach()
    {
        $res            = $this->resource;
        $this->resource = null;
        return $res;
    }

    public function getSize(): ?int
    {
        return $this->resource ? fstat($this->resource)['size'] ?? null : null;
    }

    public function tell(): int
    {
        $this->ensure();
        $pos = ftell($this->resource);
        if ($pos === false) { throw new RuntimeException('tell failed'); }
        return $pos;
    }

    public function eof(): bool   { return !$this->resource || feof($this->resource); }
    public function isSeekable(): bool { return $this->seekable; }
    public function seek($offset, $whence = SEEK_SET): void
    {
        $this->ensure();
        if (!$this->seekable || fseek($this->resource, $offset, $whence) === -1) {
            throw new RuntimeException('seek failed');
        }
    }
    public function rewind(): void { $this->seek(0); }

    public function isWritable(): bool { return $this->writable; }
    public function write($string): int
    {
        $this->ensure();
        if (!$this->writable) { throw new RuntimeException('not writable'); }
        $bytes = fwrite($this->resource, $string);
        if ($bytes === false) { throw new RuntimeException('write failed'); }
        return $bytes;
    }

    public function isReadable(): bool { return $this->readable; }
    public function read($length): string
    {
        $this->ensure();
        if (!$this->readable) { throw new RuntimeException('not readable'); }
        $data = fread($this->resource, $length);
        if ($data === false) { throw new RuntimeException('read failed'); }
        return $data;
    }

    public function getContents(): string
    {
        $this->ensure();
        $data = stream_get_contents($this->resource);
        if ($data === false) { throw new RuntimeException('getContents failed'); }
        return $data;
    }

    public function getMetadata($key = null)
    {
        if (!$this->resource) { return $key ? null : []; }
        $meta = stream_get_meta_data($this->resource);
        return $key === null ? $meta : ($meta[$key] ?? null);
    }

    /* -------------------------------------------------------------- */
    private function ensure(): void
    {
        if (!$this->resource) { throw new RuntimeException('stream detached'); }
    }
}
