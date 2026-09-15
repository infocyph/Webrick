<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Infocyph\Runwire\CancellationToken;
use Infocyph\Runwire\Http\RequestBodyInterface;
use Infocyph\Webrick\Interfaces\BodyStream;
use RuntimeException;

/** Non-seekable, low-copy view over a Runwire request body. */
final class RunwireRequestBodyStream implements BodyStream
{
    private bool $closed = false;

    private int $position = 0;

    public function __construct(
        private readonly RequestBodyInterface $body,
        private readonly CancellationToken $cancellation,
    ) {}

    public function __toString(): string
    {
        return $this->closed ? '' : $this->getContents();
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function detach(): mixed
    {
        $this->close();

        return null;
    }

    public function eof(): bool
    {
        return $this->closed || $this->body->eof();
    }

    public function getContents(): string
    {
        $this->assertOpen();
        $contents = '';
        while (!$this->body->eof()) {
            $chunk = $this->read(65_536);
            if ($chunk !== '') {
                $contents .= $chunk;
            }
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        $metadata = [
            'stream_type' => 'runwire-request-body',
            'mode' => 'r',
            'unread_bytes' => $this->closed ? 0 : $this->body->bufferedBytes(),
            'seekable' => false,
            'uri' => 'runwire://request-body',
        ];

        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }

    public function getSize(): ?int
    {
        if ($this->closed || $this->body->trailers() === null) {
            return null;
        }

        return $this->body->receivedBytes();
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function read(int $length): string
    {
        $this->assertOpen();
        if ($length < 0) {
            throw new RuntimeException('Read length must be >= 0');
        }
        if ($length === 0) {
            return '';
        }

        while (true) {
            $this->cancellation->throwIfCancelled();
            $chunk = $this->body->read($length);
            if ($chunk !== '') {
                $this->position += strlen($chunk);

                return $chunk;
            }
            if ($this->body->eof()) {
                return '';
            }

            RunwireResponseContinuation::awaitBodyReadable($this->body, $this->cancellation);
        }
    }

    public function rewind(): void
    {
        throw new RuntimeException('Runwire request body streams are not seekable.');
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        unset($offset, $whence);

        throw new RuntimeException('Runwire request body streams are not seekable.');
    }

    public function tell(): int
    {
        $this->assertOpen();

        return $this->position;
    }

    public function write(string $string): int
    {
        unset($string);

        throw new RuntimeException('Runwire request body streams are read-only.');
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new RuntimeException('Stream detached');
        }
    }
}
