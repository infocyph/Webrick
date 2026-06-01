<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Internal;

use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Core\Stream;
use JsonSerializable;
use RuntimeException;

/**
 * Stream wrapper that defers json_encode() until the first read / cast.
 *
 * Accepts either a callable returning any value or a JsonSerializable.
 * Once encoded, it replaces itself with an internal Stream instance and
 * transparently proxies every subsequent StreamInterface call.
 */
final class LazyJsonStream implements BodyStream
{
    private ?Stream $inner = null;   // real stream after first use

    /** @var callable|JsonSerializable */
    private $source;

    /**
     * @param callable|JsonSerializable $source
     */
    public function __construct(mixed $source, private readonly int $flags, private readonly int $depth)
    {
        $this->source = $source;
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
        $this->boot();
        $this->inner->close();
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
        $this->boot();

        return $this->inner->detach();
    }

    /**
     * Test whether the stream pointer is at end-of-file.
     *
     * @return bool True if EOF has been reached, false otherwise
     */
    public function eof(): bool
    {
        $this->boot();

        return $this->inner->eof();
    }

    /**
     * Return the remaining contents of the stream as a string.
     *
     * @return string Remaining stream contents
     */
    public function getContents(): string
    {
        $this->boot();

        return $this->inner->getContents();
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
        $this->boot();

        return $this->inner->getMetadata($key);
    }

    /**
     * Return the size of the underlying stream if known.
     *
     * @return int|null Size in bytes, or null if unknown
     */
    public function getSize(): ?int
    {
        $this->boot();

        return $this->inner->getSize();
    }

    /**
     * Determine whether the stream is readable.
     *
     * @return bool True if read operations are supported
     */
    public function isReadable(): bool
    {
        $this->boot();

        return $this->inner->isReadable();
    }

    /**
     * Determine whether the stream is seekable.
     *
     * @return bool True if seek operations are supported
     */
    public function isSeekable(): bool
    {
        $this->boot();

        return $this->inner->isSeekable();
    }

    /**
     * Determine whether the stream is writable.
     *
     * @return bool True if write operations are supported
     */
    public function isWritable(): bool
    {
        $this->boot();

        return $this->inner->isWritable();
    }

    /**
     * Read up to $length bytes from the stream.
     *
     * @param int $length Maximum number of bytes to read
     * @return string Data read (may be shorter than $length)
     */
    public function read(int $length): string
    {
        $this->boot();

        return $this->inner->read($length);
    }

    /**
     * Rewind the stream pointer to the beginning.
     */
    public function rewind(): void
    {
        $this->boot();
        $this->inner->rewind();
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
        $this->boot();
        $this->inner->seek($offset, $whence);
    }

    /**
     * Return the current position of the stream pointer.
     *
     * @return int Current position in bytes from the beginning of the stream
     */
    public function tell(): int
    {
        $this->boot();

        return $this->inner->tell();
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
        $this->boot();

        return $this->inner->write($string);
    }

    /* -------------------------------------------------- lazy bootstrap */
    /**
     * Initialize the internal Stream by JSON-encoding the source on first use.
     *
     * - If $this->source is callable it will be invoked to obtain the value to encode.
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

        $payload = \is_callable($this->source)
            ? ($this->source)()
            : $this->source->jsonSerialize();

        $json = \json_encode($payload, $this->flags, $this->depth);
        if ($json === false) {
            throw new RuntimeException('JSON encode error: ' . \json_last_error_msg());
        }
        $this->inner = new Stream($json);
    }
}
