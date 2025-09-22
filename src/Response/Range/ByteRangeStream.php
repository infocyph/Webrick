<?php

namespace Infocyph\Webrick\Response\Range;

use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Core\Stream;
use RuntimeException;

final class ByteRangeStream implements BodyStream
{
    private Stream $base;
    private int $remaining;

    /**
     * Create a read-only windowed stream that exposes at most $limit bytes
     * from the provided base Stream.
     *
     * @param Stream $base Underlying stream to read from.
     * @param int $limit Maximum number of bytes available to reads from this wrapper.
     */
    public function __construct(Stream $base, int $limit)
    {
        $this->base = $base;
        $this->remaining = $limit;
    }

    /* -------- PSR-7: forwarding with minimal overrides -------- */

    /**
     * Cast the stream to a string.
     *
     * Rewinds the underlying stream then returns the remaining windowed
     * contents as a string. This will consume the available bytes.
     *
     * @return string Windowed stream contents
     */
    public function __toString(): string
    {
        $this->rewind();
        return $this->getContents();
    }

    /**
     * Close the underlying stream.
     *
     * @return void
     */
    public function close(): void
    {
        $this->base->close();
    }

    /**
     * Detach the underlying stream/resource and return it.
     *
     * Behaviour mirrors Stream::detach() of the wrapped stream.
     *
     * @return mixed Detached underlying resource (implementation dependent)
     */
    public function detach(): mixed
    {
        return $this->base->detach();
    }

    /**
     * Check whether the stream has reached end-of-file for this window.
     *
     * Returns true when either the window quota is exhausted or the base
     * stream is at EOF.
     *
     * @return bool True if no more bytes can be read
     */
    public function eof(): bool
    {
        return $this->remaining === 0 || $this->base->eof();
    }

    /**
     * Return the remaining contents of the underlying stream truncated to
     * the window quota. Consumes the returned bytes and reduces the quota.
     *
     * @return string Remaining contents up to the window limit
     */
    public function getContents(): string
    {
        $data = $this->base->getContents();
        $data = substr($data, 0, $this->remaining);
        $this->remaining -= strlen($data);
        return $data;
    }

    /**
     * Retrieve metadata from the underlying stream.
     *
     * If $key is null the full metadata array is returned; otherwise the
     * corresponding metadata value or null.
     *
     * @param string|null $key Optional metadata key.
     * @return mixed Metadata value or array as returned by the base stream
     */
    public function getMetadata(?string $key = null): mixed
    {
        return $this->base->getMetadata($key);
    }

    /**
     * Return the number of bytes remaining in this window.
     *
     * Note: this reports the remaining quota, not the total size of the
     * underlying stream resource.
     *
     * @return int|null Remaining bytes available to read, or null if unknown
     */
    public function getSize(): ?int
    {
        return $this->remaining;
    }

    /**
     * Whether the underlying stream is readable.
     *
     * @return bool True if the base stream supports reads
     */
    public function isReadable(): bool
    {
        return $this->base->isReadable();
    }

    /**
     * Whether the underlying stream supports seeking.
     *
     * @return bool True if seek operations are supported by the base stream
     */
    public function isSeekable(): bool
    {
        return $this->base->isSeekable();
    }

    /**
     * This wrapper is read-only.
     *
     * @return bool Always false
     */
    public function isWritable(): bool
    {
        return false; // read-only
    }

    /**
     * Read up to $length bytes from the underlying stream, capped by the
     * remaining window quota. Decrements the remaining quota by the number
     * of bytes actually read.
     *
     * @param int $length Maximum number of bytes to read.
     * @return string Bytes read (may be shorter than $length).
     */
    public function read(int $length): string
    {
        if ($this->remaining === 0) {
            return '';
        }

        $length = min($length, $this->remaining);
        $chunk = $this->base->read($length);

        $this->remaining -= strlen($chunk);
        return $chunk;
    }

    /**
     * Rewind the underlying stream to the start.
     *
     * @return void
     * @throws RuntimeException If the underlying stream is not seekable.
     */
    public function rewind(): void
    {
        $this->seek(0);
    }

    /**
     * Seek the underlying stream.
     *
     * Note: seeking does not modify the remaining byte quota; callers
     * must ensure semantics they expect when combining seek + read.
     *
     * @param int $offset Byte offset to seek to on the underlying stream.
     * @param int $whence One of SEEK_SET, SEEK_CUR or SEEK_END.
     * @return void
     * @throws RuntimeException If the underlying stream is not seekable.
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (!$this->isSeekable()) {
            throw new RuntimeException('Stream is not seek-able');
        }
        $this->base->seek($offset, $whence);
    }

    /**
     * Return the current read pointer of the underlying stream.
     *
     * The value relates to the base stream; callers should consider that
     * $this->remaining is decremented independently when reading.
     *
     * @return int Current position in the underlying stream
     */
    public function tell(): int
    {
        return $this->base->tell();
    }

    /**
     * Writing is not supported for this wrapper.
     *
     * @param string $string Ignored
     * @return int Never returns; always throws
     * @throws RuntimeException Always thrown because the stream is read-only
     */
    public function write(string $string): int
    {
        throw new RuntimeException('ByteRangeStream is read-only');
    }
}
