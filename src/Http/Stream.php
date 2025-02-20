<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Http;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

class Stream implements StreamInterface
{
    /** @var resource|null */
    private $resource;

    private readonly bool $readable;

    private readonly bool $writable;

    private readonly bool $seekable;

    public function __construct($input)
    {
        if (is_string($input)) {
            // create in-memory stream
            $this->resource = fopen('php://temp', 'r+');
            fwrite($this->resource, $input);
            fseek($this->resource, 0);
        } elseif (is_resource($input)) {
            $this->resource = $input;
        } else {
            throw new InvalidArgumentException('Stream must be created from string or resource.');
        }

        $meta = stream_get_meta_data($this->resource);
        $mode = $meta['mode'];
        $this->seekable = $meta['seekable'];
        $this->readable = (strpbrk($mode, 'r+') !== false);
        $this->writable = (strpbrk($mode, 'wxa+') !== false);
    }

    public function __toString(): string
    {
        try {
            if (!$this->resource) {
                return '';
            }
            $this->rewind();

            return (string)stream_get_contents($this->resource);
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if ($this->resource) {
            fclose($this->resource);
            $this->resource = null;
        }
    }

    public function detach()
    {
        $res = $this->resource;
        $this->resource = null;

        return $res;
    }

    public function getSize(): ?int
    {
        if (!$this->resource) {
            return null;
        }
        $stats = fstat($this->resource);

        return $stats['size'] ?? null;
    }

    public function tell(): int
    {
        $this->ensureResource();
        $pos = ftell($this->resource);
        if ($pos === false) {
            throw new RuntimeException('Unable to determine position.');
        }

        return $pos;
    }

    public function eof(): bool
    {
        return !$this->resource || feof($this->resource);
    }

    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        $this->ensureResource();
        if (!$this->seekable) {
            throw new RuntimeException('Stream not seekable.');
        }
        if (fseek($this->resource, $offset, $whence) === -1) {
            throw new RuntimeException('Stream seek failed.');
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
        $this->ensureResource();
        if (!$this->writable) {
            throw new RuntimeException('Stream not writable.');
        }
        $bytes = fwrite($this->resource, $string);
        if ($bytes === false) {
            throw new RuntimeException('Write failed.');
        }

        return $bytes;
    }

    public function isReadable(): bool
    {
        return $this->readable;
    }

    public function read($length): string
    {
        $this->ensureResource();
        if (!$this->readable) {
            throw new RuntimeException('Stream not readable.');
        }
        $data = fread($this->resource, $length);
        if ($data === false) {
            throw new RuntimeException('Read failed.');
        }

        return $data;
    }

    public function getContents(): string
    {
        $this->ensureResource();
        $content = stream_get_contents($this->resource);
        if ($content === false) {
            throw new RuntimeException('Unable to read contents.');
        }

        return $content;
    }

    public function getMetadata($key = null)
    {
        if (!$this->resource) {
            return $key ? null : [];
        }
        $meta = stream_get_meta_data($this->resource);
        if ($key === null) {
            return $meta;
        }

        return $meta[$key] ?? null;
    }

    private function ensureResource(): void
    {
        if (!$this->resource) {
            throw new RuntimeException('Stream is detached.');
        }
    }
}
