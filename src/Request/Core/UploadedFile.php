<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Core;

use Infocyph\Webrick\Interfaces\BodyStream;
use InvalidArgumentException;
use RuntimeException;

/** Allocation-aware uploaded-file value object with caller-owned target paths. */
final class UploadedFile
{
    private readonly int $err;

    private bool $moved = false;

    public function __construct(
        private string|BodyStream $src,
        private readonly ?int $size = null,
        int $err = UPLOAD_ERR_OK,
        private readonly ?string $clientName = null,
        private readonly ?string $clientType = null,
    ) {
        if ($err < 0 || $err > 8) {
            throw new InvalidArgumentException('Invalid upload error code');
        }
        if ($this->size !== null && $this->size < 0) {
            throw new InvalidArgumentException('Upload size must be zero or greater');
        }
        $this->err = $err;
    }

    /**
     * @param array{
     *   tmp_name?: string,
     *   size?: int|null,
     *   error?: int,
     *   name?: string|null,
     *   type?: string|null
     * } $spec
     */
    public static function fromSpec(array $spec): self
    {
        return new self(
            is_string($spec['tmp_name'] ?? null) ? $spec['tmp_name'] : '',
            is_int($spec['size'] ?? null) ? $spec['size'] : null,
            is_int($spec['error'] ?? null) ? $spec['error'] : UPLOAD_ERR_NO_FILE,
            is_string($spec['name'] ?? null) ? $spec['name'] : null,
            is_string($spec['type'] ?? null) ? $spec['type'] : null,
        );
    }

    public function getClientFilename(): ?string
    {
        return $this->clientName;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientType;
    }

    public function getError(): int
    {
        return $this->err;
    }

    public function getSize(): ?int
    {
        if ($this->size !== null) {
            return $this->size;
        }
        if (is_string($this->src) && is_file($this->src)) {
            $size = filesize($this->src);

            return $size === false ? null : $size;
        }

        return $this->src instanceof BodyStream ? $this->src->getSize() : null;
    }

    public function getStream(): BodyStream
    {
        $this->assertOkAndNotMoved();

        if (is_string($this->src)) {
            $handle = fopen($this->src, 'rb');
            if (!is_resource($handle)) {
                throw new RuntimeException("Cannot open uploaded file: {$this->src}");
            }
            $this->src = new Stream($handle);
        }

        return $this->src;
    }

    public function moveTo(string $targetPath): void
    {
        $this->assertOkAndNotMoved();
        $this->assertTarget($targetPath);

        if (is_string($this->src)) {
            $ok = is_uploaded_file($this->src)
                ? move_uploaded_file($this->src, $targetPath)
                : rename($this->src, $targetPath);
            if (!$ok) {
                throw new RuntimeException("Failed to move uploaded file to {$targetPath}");
            }
        } else {
            $this->copyStreamTo($targetPath);
        }

        $this->moved = true;
    }

    private function assertOkAndNotMoved(): void
    {
        if ($this->err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File was not uploaded successfully');
        }
        if ($this->moved) {
            throw new RuntimeException('Uploaded file has been moved');
        }
    }

    private function assertTarget(string $path): void
    {
        if ($path === '') {
            throw new InvalidArgumentException('Target path must be non-empty');
        }

        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException("Upload target directory must exist and be writable: {$directory}");
        }
    }

    private function copyStreamTo(string $targetPath): void
    {
        $source = $this->src;
        if (!$source instanceof BodyStream) {
            throw new RuntimeException('Uploaded stream is unavailable.');
        }
        if ($source->isSeekable()) {
            $source->rewind();
        }

        $out = fopen($targetPath, 'wb');
        if (!is_resource($out)) {
            throw new RuntimeException("Cannot write to {$targetPath}");
        }

        $completed = false;
        try {
            while (!$source->eof()) {
                $chunk = $source->read(65_536);
                if ($chunk === '') {
                    break;
                }

                $offset = 0;
                $length = strlen($chunk);
                while ($offset < $length) {
                    $written = fwrite($out, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException("Failed to write uploaded file to {$targetPath}");
                    }
                    $offset += $written;
                }
            }
            $completed = true;
        } finally {
            fclose($out);
            if (!$completed && is_file($targetPath)) {
                @unlink($targetPath);
            }
        }
    }
}
