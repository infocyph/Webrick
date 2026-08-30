<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Internal;

use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Core\StringBody;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;

/**
 * Lazy JSON body. Encoding happens once on first observation and the encoded
 * representation remains a native in-memory string body; no temp stream is
 * created after json_encode().
 */
final class LazyJsonStream implements BodyStream
{
    /** @var int<1,max> */
    private readonly int $depth;

    private ?StringBody $inner = null;

    public function __construct(private readonly JsonSerializable $source, private readonly int $flags, int $depth)
    {
        if ($depth < 1) {
            throw new InvalidArgumentException('JSON depth must be at least 1.');
        }
        $this->depth = $depth;
    }

    public function __toString(): string
    {
        return (string) $this->body();
    }

    public function close(): void
    {
        $this->body()->close();
    }

    public function detach(): mixed
    {
        return $this->body()->detach();
    }

    public function eof(): bool
    {
        return $this->body()->eof();
    }

    public function getContents(): string
    {
        return $this->body()->getContents();
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $this->body()->getMetadata($key);
    }

    public function getSize(): ?int
    {
        return $this->body()->getSize();
    }

    public function isReadable(): bool
    {
        return $this->body()->isReadable();
    }

    public function isSeekable(): bool
    {
        return $this->body()->isSeekable();
    }

    public function isWritable(): bool
    {
        return $this->body()->isWritable();
    }

    public function read(int $length): string
    {
        return $this->body()->read($length);
    }

    public function rewind(): void
    {
        $this->body()->rewind();
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->body()->seek($offset, $whence);
    }

    public function tell(): int
    {
        return $this->body()->tell();
    }

    public function write(string $string): int
    {
        return $this->body()->write($string);
    }

    private function body(): StringBody
    {
        if ($this->inner instanceof StringBody) {
            return $this->inner;
        }

        $json = json_encode($this->source->jsonSerialize(), $this->flags, $this->depth);
        if ($json === false) {
            throw new RuntimeException('JSON encode error: ' . json_last_error_msg());
        }

        return $this->inner = new StringBody($json);
    }
}
