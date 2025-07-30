<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Internal;

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
final class LazyJsonStream
{
    /** @var callable|JsonSerializable */
    private $source;

    private int $flags;
    private int $depth;
    private ?Stream $inner = null;   // real stream after first use

    /**
     * @param callable|JsonSerializable $source
     */
    public function __construct(mixed $source, int $flags, int $depth)
    {
        $this->source = $source;
        $this->flags  = $flags;
        $this->depth  = $depth;
    }

    /* -------------------------------------------------- lazy bootstrap */

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

    /* -------------------------------------------------- proxy layer */

    public function __toString(): string
    {
        $this->boot();
        return (string) $this->inner;
    }
    public function close(): void
    {
        $this->boot();
        $this->inner->close();
    }
    public function detach(): mixed
    {
        $this->boot();
        return $this->inner->detach();
    }
    public function getSize(): ?int
    {
        $this->boot();
        return $this->inner->getSize();
    }
    public function tell(): int
    {
        $this->boot();
        return $this->inner->tell();
    }
    public function eof(): bool
    {
        $this->boot();
        return $this->inner->eof();
    }
    public function isSeekable(): bool
    {
        $this->boot();
        return $this->inner->isSeekable();
    }
    public function seek($offset, $whence = SEEK_SET): void
    {
        $this->boot();
        $this->inner->seek($offset, $whence);
    }
    public function rewind(): void
    {
        $this->boot();
        $this->inner->rewind();
    }
    public function isWritable(): bool
    {
        $this->boot();
        return $this->inner->isWritable();
    }
    public function write($string): int
    {
        $this->boot();
        return $this->inner->write($string);
    }
    public function isReadable(): bool
    {
        $this->boot();
        return $this->inner->isReadable();
    }
    public function read($length): string
    {
        $this->boot();
        return $this->inner->read($length);
    }
    public function getContents(): string
    {
        $this->boot();
        return $this->inner->getContents();
    }
    public function getMetadata($key = null): mixed
    {
        $this->boot();
        return $this->inner->getMetadata($key);
    }
}
