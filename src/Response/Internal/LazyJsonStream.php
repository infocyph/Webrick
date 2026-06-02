<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Internal;

use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Core\Stream;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;

/**
 * Stream wrapper that defers json_encode() until the first read / cast.
 *
 * Accepts a JsonSerializable payload producer.
 * Once encoded, it replaces itself with an internal Stream instance and
 * transparently proxies every subsequent StreamInterface call.
 */
final class LazyJsonStream implements BodyStream
{
    /** @var int<1, max> */
    private readonly int $depth;

    private ?Stream $inner = null;

    public function __construct(private readonly JsonSerializable $source, private readonly int $flags, int $depth)
    {
        if ($depth < 1) {
            throw new InvalidArgumentException('JSON depth must be at least 1.');
        }

        $this->depth = $depth;
    }

    /* -------------------------------------------------- proxy layer */

    /**
     * Cast the stream to string, triggering lazy JSON encoding if needed.
     *
     * This returns the full JSON payload as a string.
     *
     * @return string JSON representation of the underlying payload
     */
    public function __toString(): string
    {
        $this->boot();

        return (string) $this->inner;
    }

    /**
     * Close the underlying stream resource.
     *
     * Ensures the stream is initialized before delegating to the inner stream.
     */
    public function close(): void
    {
        $this->stream()->close();
    }

    /**
     * Detach the underlying stream resource and return the underlying PHP resource.
     *
     * The stream is initialized first. The return value is implementation-dependent
     * (mixed) and mirrors the behaviour of the wrapped Stream::detach().
     *
     * @return mixed Underlying detached resource (or null) as returned by inner->detach()
     */
    public function detach(): mixed
    {
        return $this->stream()->detach();
    }

    /**
     * Test whether the stream pointer is at end-of-file.
     *
     * @return bool True if EOF has been reached, false otherwise
     */
    public function eof(): bool
    {
        return $this->stream()->eof();
    }

    /**
     * Return the remaining contents of the stream as a string.
     *
     * @return string Remaining stream contents
     */
    public function getContents(): string
    {
        return $this->stream()->getContents();
    }

    /**
     * Get metadata from the underlying stream.
     *
     * If $key is null the full metadata array is returned, otherwise the value
     * for the given key (or null if not present).
     *
     * @param string|null $key Optional metadata key
     * @return mixed Metadata value or array as provided by the inner stream
     */
    public function getMetadata(?string $key = null): mixed
    {
        return $this->stream()->getMetadata($key);
    }

    /**
     * Return the size of the underlying stream if known.
     *
     * @return int|null Size in bytes, or null if unknown
     */
    public function getSize(): ?int
    {
        return $this->stream()->getSize();
    }

    /**
     * Determine whether the stream is readable.
     *
     * @return bool True if read operations are supported
     */
    public function isReadable(): bool
    {
        return $this->stream()->isReadable();
    }

    /**
     * Determine whether the stream is seekable.
     *
     * @return bool True if seek operations are supported
     */
    public function isSeekable(): bool
    {
        return $this->stream()->isSeekable();
    }

    /**
     * Determine whether the stream is writable.
     *
     * @return bool True if write operations are supported
     */
    public function isWritable(): bool
    {
        return $this->stream()->isWritable();
    }

    /**
     * Read up to $length bytes from the stream.
     *
     * @param int $length Maximum number of bytes to read
     * @return string Data read (may be shorter than $length)
     */
    public function read(int $length): string
    {
        return $this->stream()->read($length);
    }

    /**
     * Rewind the stream pointer to the beginning.
     */
    public function rewind(): void
    {
        $this->stream()->rewind();
    }

    /**
     * Seek to a position in the stream.
     *
     * Delegates to the inner stream after ensuring initialization.
     *
     * @param int $offset Byte offset to seek to
     * @param int $whence SEEK_SET, SEEK_CUR or SEEK_END
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->stream()->seek($offset, $whence);
    }

    /**
     * Return the current position of the stream pointer.
     *
     * @return int Current position in bytes from the beginning of the stream
     */
    public function tell(): int
    {
        return $this->stream()->tell();
    }

    /**
     * Write data to the stream.
     *
     * Ensures initialization, writes $string to the underlying stream and
     * returns the number of bytes written.
     *
     * @param string $string Data to write
     * @return int Number of bytes written
     */
    public function write(string $string): int
    {
        return $this->stream()->write($string);
    }

    /* -------------------------------------------------- lazy bootstrap */
    /**
     * Initialize the internal Stream by JSON-encoding the source on first use.
     *
     * - If $this->source implements JsonSerializable its jsonSerialize() result is used.
     * - On JSON encoding failure a RuntimeException is thrown.
     *
     * After successful encoding an internal Stream instance is created and stored
     * in $this->inner for all subsequent calls.
     *
     * @throws RuntimeException When json_encode() fails
     */
    private function boot(): void
    {
        if ($this->inner !== null) {
            return;
        }

        $payload = $this->source->jsonSerialize();

        $json = \json_encode($payload, $this->flags, $this->depth);
        if ($json === false) {
            throw new RuntimeException('JSON encode error: ' . \json_last_error_msg());
        }
        $this->inner = new Stream($json);
    }

    private function stream(): Stream
    {
        $this->boot();
        if ($this->inner === null) {
            throw new RuntimeException('LazyJsonStream failed to initialize.');
        }

        return $this->inner;
    }
}
