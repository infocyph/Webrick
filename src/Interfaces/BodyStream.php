<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Interfaces;

interface BodyStream
{
    public function __toString(): string;

    public function close(): void;

    public function detach(): mixed;

    public function getSize(): ?int;

    public function tell(): int;

    public function eof(): bool;

    public function isSeekable(): bool;

    public function seek($offset, $whence = SEEK_SET): void;

    public function rewind(): void;

    public function isWritable(): bool;

    public function write($string): int;

    public function isReadable(): bool;

    public function read($length): string;

    public function getContents(): string;

    public function getMetadata($key = null): mixed;
}
