<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Interfaces;

interface BodyStream
{
    /**
     * Return the contents of the stream as a string.
     *
     * @return string The contents of the stream.
     */
    public function __toString(): string;

    /**
     * Close the stream.
     *
     * Closes the underlying stream.
     */
    public function close(): void;

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
    public function detach(): mixed;

    /**
     * Returns the size of the stream if known.
     *
     * @return int|null The size of the stream in bytes if known, or null if unknown.
     */
    public function getSize(): ?int;

    /**
     * Returns the current position of the file read/write pointer.
     *
     * @return int The current position of the file read/write pointer.
     */
    public function tell(): int;

    /**
     * Returns true if the stream is at end-of-file (EOF).
     *
     * The EOF state of a stream often changes after a successful read
     * operation.
     *
     * The local stream instance MUST be in a usable state and might not have open
     * errors set prior to calling this method.
     *
     * @return bool True if the stream is at EOF, false otherwise.
     */
    public function eof(): bool;

    /**
     * Returns whether or not the stream is seekable.
     *
     * Streams which are not seekable can only be read from the beginning.
     *
     * @return bool True if the stream is seekable, false otherwise.
     */
    public function isSeekable(): bool;

    /**
     * Seek to a position in the stream.
     *
     * @param int $offset The stream offset to seek to.
     * @param int $whence One of SEEK_SET, SEEK_CUR, or SEEK_END to specify the seek
     *              mode.
     *
     * @throws \RuntimeException on failure.
     */
    public function seek(int $offset, int $whence = SEEK_SET): void;

    /**
     * Rewind the stream.
     *
     * If the stream is seekable, rewind to the beginning of the stream.
     *
     * @throws \RuntimeException on failure.
     */
    public function rewind(): void;

    /**
     * Returns whether or not the stream is writable.
     *
     * @return bool True if the stream is writable, false otherwise.
     */
    public function isWritable(): bool;

    /**
     * Writes data to the stream.
     *
     * @param string $string The string that should be written.
     *
     * @return int The number of bytes written to the stream.
     *
     * @throws \RuntimeException on failure.
     */
    public function write(string $string): int;

    /**
     * Returns whether or not the stream is readable.
     *
     * @return bool True if the stream is readable, false otherwise.
     */
    public function isReadable(): bool;

    /**
     * Read data from the stream.
     *
     * @param int $length The maximum number of bytes to read.
     *
     * @return string The data read from the stream.
     *
     * @throws \RuntimeException on failure.
     */
    public function read(int $length): string;

    /**
     * Returns the remaining contents in a string of up to max bytes.
     *
     * @return string The remaining contents.
     *
     * @throws \RuntimeException if unable to read.
     */
    public function getContents(): string;

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
    public function getMetadata(?string $key = null): mixed;
}
