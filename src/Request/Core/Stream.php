<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Core;

use Infocyph\Webrick\Interfaces\BodyStream;
use RuntimeException;
use SplFileObject;

/**
 * Tiny, allocation-aware PSR-7 stream.
 *
 *  • Accepts:  string payload · fopen() handle · SplFileObject · another Stream
 *  • Determines readable / writable flags once in the ctor (cheap bit-test)
 *  • Never buffers entire file unless you explicitly cast to string
 *  • All operations throw RuntimeException on error – *never* return false
 */
final class Stream implements BodyStream
{
    /** Verified PHP stream handle (resource|null after detach/close) */
    private mixed $h;

    private bool $readable;
    private bool $writable;

    /**
     * Initializes a new Stream instance.
     *
     * Accepts a string, a PSR-7 Stream, a PHP stream resource, or a SplFileObject.
     * If the given source is invalid, it will throw a RuntimeException.
     *
     * @param mixed $source
     * @throws RuntimeException If the given source is invalid.
     */
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

    /**
     * Return the contents of the stream as a string.
     *
     * This method will work even if the stream is not rewindable.
     * It will also not copy the underlying stream resource, so it is
     * safe to use with large streams.
     *
     * @return string The contents of the stream.
     */
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

    /**
     * Close the stream and free up any associated resources.
     *
     * Note: When a stream is closed, it is no longer possible to read from
     * or write to it. Any subsequent calls to {@see StreamInterface::read()}
     * or {@see StreamInterface::write()} will result in an error.
     */
    public function close(): void
    {
        if ($this->h) {
            fclose($this->h);
        }
        $this->h = null;
    }

    /**
     * Separates any underlying resources from the stream.
     *
     * After the stream has been detached, you can reattach it to a new stream
     * using {@see attach()}, allowing streams to be reused and stream objects to be
     * garbage collected sooner.
     *
     * If the stream is based on a PHP stream, this will detach the underlying stream
     * resource from the stream. The result is a stream resource that can be reattached
     * later using {@see attach()}.
     *
     * If the stream is not based on a PHP stream, this is a no-op.
     *
     * @return mixed The underlying stream resource, or null if the stream is not based on a PHP stream.
     */
    public function detach(): mixed
    {
        $h = $this->h;
        $this->h = null;
        return $h;
    }

    /**
     * Returns true if the stream is at end-of-file (EOF).
     *
     * Note that this function does not guarantee that the stream has reached
     * the end of the underlying resource. It only checks whether the stream
     * is currently at the end of the file.
     *
     * @return bool True if the stream is at EOF, false otherwise.
     */
    public function eof(): bool
    {
        return !$this->h || feof($this->h);
    }

    /**
     * Read the remaining contents of the stream as a string.
     *
     * If the stream is not readable, a RuntimeException is thrown.
     *
     * @return string The remaining contents of the stream.
     * @throws RuntimeException If unable to read stream contents.
     *
     */
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

    /**
     * Retrieve stream metadata as an associative array or specific key.
     *
     * The keys returned are identical to the keys accepted by
     * {@see stream_get_meta_data()} and are as follows:
     *
     * - `'timeout'`: The timeout in seconds.
     * - `'stream_type'`: The type of the underlaying stream, such as `'socket'`
     *   or `'STDIO'`.
     * - `'mode'`: The access mode used when opening the stream, such as `'r'`
     *   or `'w'`.
     * - `'unread_bytes'`: The number of bytes that are left unread.
     * - `'seekable'`: Whether the stream is seekable.
     * - `'uri'`: The URI/resource that the underlaying stream represents.
     *
     * @param string|null $key The metadata key to retrieve.
     *
     * @return array<string, mixed>|mixed The metadata as an associative array or value of the specified key.
     */
    public function getMetadata(?string $key = null): mixed
    {
        if (!$this->h) {
            return $key ? null : [];
        }
        $meta = stream_get_meta_data($this->h);
        return $key ? ($meta[$key] ?? null) : $meta;
    }

    /**
     * Returns the size of the stream if known.
     *
     * @return int|null The size of the stream in bytes if known, or null if unknown.
     */
    public function getSize(): ?int
    {
        return $this->h ? (fstat($this->h)['size'] ?? null) : null;
    }

    /**
     * Returns whether or not the stream is readable.
     *
     * @return bool True if the stream is readable, false otherwise.
     */
    public function isReadable(): bool
    {
        return $this->readable;
    }

    /**
     * Returns whether or not the stream is seekable.
     *
     * A stream is considered seekable if it supports seeking to a specific
     * position in the stream. This means that the stream must be able to
     * jump to a specific position in the stream, such as the beginning or
     * end of the stream.
     *
     * @return bool True if the stream is seekable, false otherwise.
     */
    public function isSeekable(): bool
    {
        return $this->h ? (stream_get_meta_data($this->h)['seekable'] ?? false) : false;
    }

    /**
     * Returns whether or not the stream is writable.
     *
     * @return bool True if the stream is writable, false otherwise.
     */
    public function isWritable(): bool
    {
        return $this->writable;
    }

    /**
     * Reads up to `$length` bytes from the stream and returns them as a string.
     *
     * If the stream is not readable, a RuntimeException is thrown.
     *
     * If an error occurs while reading, a RuntimeException is thrown.
     *
     * @param int $length The number of bytes to read from the stream.
     * @return string The data read from the stream.
     * @throws RuntimeException If unable to read from the stream.
     */
    public function read(int $length): string
    {
        if (!$this->readable) {
            throw new RuntimeException('Stream not readable');
        }
        $data = fread($this->need(), $length);
        if ($data === false) {
            throw new RuntimeException('Stream read failed');
        }
        return $data;
    }

    /**
     * Rewind the stream.
     *
     * Resets the stream position to the beginning of the stream.
     */
    public function rewind(): void
    {
        $this->doSeek(0);
    }

    /**
     * Seek to a position in the stream.
     *
     * @param int $offset The stream offset to seek to.
     * @param int $whence One of SEEK_SET, SEEK_CUR, or SEEK_END to specify the seek mode.
     *
     * @throws RuntimeException on failure.
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->doSeek($offset, $whence);
    }

    /**
     * Returns the current position of the file read/write pointer.
     *
     * @return int The current position of the file read/write pointer.
     * @throws RuntimeException If unable to determine stream position.
     */
    public function tell(): int
    {
        $pos = ftell($this->need());
        if ($pos === false) {
            throw new RuntimeException('Unable to determine stream position');
        }
        return $pos;
    }

    /**
     * Writes a string to the stream.
     *
     * If the stream is not writable, a RuntimeException is thrown.
     *
     * If the write fails, a RuntimeException is thrown with a message of
     * "Stream write failed".
     *
     * @param string $string The string to write to the stream.
     * @return int The number of bytes written to the stream.
     * @throws RuntimeException If the stream is not writable or the write fails.
     */
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

    /**
     * Opens a PHP stream from an SplFileObject.
     *
     * This static helper method takes an SplFileObject and returns a PHP stream
     * handle (resource) opened in read-only or read-write mode, depending on the
     * file's permissions.
     *
     * @throws RuntimeException If the file is not readable or cannot be opened.
     */
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

    /**
     * Open a PHP stream for a given string payload.
     *
     * @param string $payload
     * @return mixed A PHP stream handle (resource|null)
     *
     * If the payload is not empty, it will be written to the stream and the
     * stream pointer will be rewound to the beginning.
     */
    private static function openMemory(string $payload): mixed
    {
        $h = fopen('php://temp', 'r+');
        if ($payload !== '') {
            fwrite($h, $payload);
            rewind($h);
        }
        return $h;
    }

    /**
     * Low-level implementation of Stream::seek().
     * @param int $off offset in bytes
     * @param int $whence one of SEEK_SET, SEEK_CUR, SEEK_END
     * @throws RuntimeException on failure
     */
    private function doSeek(int $off, int $whence = SEEK_SET): void
    {
        if (fseek($this->need(), $off, $whence) !== 0) {
            throw new RuntimeException('Stream seek failed');
        }
    }

    /**
     * Return the underlying stream resource, or throw if the stream is detached.
     *
     * @return mixed the underlying stream resource
     * @throws RuntimeException if the stream is detached
     */
    private function need(): mixed
    {
        return $this->h ?? throw new RuntimeException('Stream detached');
    }
}
