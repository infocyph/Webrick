<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Http;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

class UploadedFile implements UploadedFileInterface
{
    private readonly int $error;

    private bool $moved = false;

    public function __construct(
        private readonly StreamInterface $stream,
        private readonly int $size,
        int $error = UPLOAD_ERR_OK,
        private readonly ?string $clientFilename = null,
        private readonly ?string $clientMediaType = null,
    ) {
        if ($error < 0 || $error > 8) {
            throw new InvalidArgumentException('Invalid upload error code.');
        }
        $this->error = $error;
    }

    public function getStream(): StreamInterface
    {
        if ($this->moved) {
            throw new RuntimeException('Cannot retrieve stream after moveTo.');
        }

        return $this->stream;
    }

    public function moveTo($targetPath): void
    {
        if ($this->moved) {
            throw new RuntimeException('File already moved.');
        }
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Cannot move file due to upload error.');
        }
        if (!is_string($targetPath) || $targetPath === '') {
            throw new InvalidArgumentException('Invalid target path.');
        }

        // Copy stream
        $this->writeStreamToFile($this->stream, $targetPath);
        $this->moved = true;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    private function writeStreamToFile(StreamInterface $stream, string $path): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create directory: {$dir}");
            }
        }
        $target = fopen($path, 'wb');
        if (!$target) {
            throw new RuntimeException("Cannot open file for writing: {$path}");
        }
        while (!$stream->eof()) {
            fwrite($target, $stream->read(8192));
        }
        fclose($target);
    }
}
