<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Http;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * Minimal PSR-7 UploadedFile implementation with:
 *   • zero-copy `move_uploaded_file()` / `rename()` when possible
 *   • on-demand Stream wrapper if you constructed from a tmp file path
 *   • lazy size detection + factory helper for `$_FILES` specs
 */
class UploadedFile implements UploadedFileInterface
{
    /** @var string|StreamInterface */
    private $source;            // either tmp-file path OR already-open stream
    private readonly ?string $clientFilename;
    private readonly ?string $clientMediaType;
    private readonly int     $error;
    private ?int             $size;
    private bool             $moved = false;

    /* --------------------------------------------------------------
     * ctor / factory
     * ------------------------------------------------------------ */
    /**
     * @param string|StreamInterface $source   tmp path OR stream
     * @param int|null               $size     bytes (0/null => auto)
     * @param int                    $error    UPLOAD_ERR_* constant
     */
    public function __construct(
        $source,
        ?int   $size = null,
        int    $error = UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null
    ) {
        if (!is_string($source) && ! $source instanceof StreamInterface) {
            throw new InvalidArgumentException('Source must be filepath or StreamInterface');
        }
        if ($error < 0 || $error > 8) {
            throw new InvalidArgumentException('Invalid upload error code');
        }
        $this->source          = $source;
        $this->size            = $size;
        $this->error           = $error;
        $this->clientFilename  = $clientFilename;
        $this->clientMediaType = $clientMediaType;
    }

    /** Build directly from `$_FILES[...]` spec */
    public static function fromSpec(array $spec): self
    {
        return new self(
            $spec['tmp_name'],
            $spec['size']     ?? null,
            $spec['error']    ?? UPLOAD_ERR_NO_FILE,
            $spec['name']     ?? null,
            $spec['type']     ?? null
        );
    }

    public function __destruct()
    {
        if ($this->source instanceof StreamInterface) {
            $this->source->close();
        }
    }

    /* --------------------------------------------------------------
     * Stream handling
     * ------------------------------------------------------------ */
    public function getStream(): StreamInterface
    {
        $this->assertOkAndNotMoved();

        if (is_string($this->source)) {
            /** @psalm-suppress PossiblyNullArgument */
            $this->source = new Stream(fopen($this->source, 'rb'));
        }

        return $this->source;
    }

    public function moveTo($targetPath): void
    {
        $this->assertOkAndNotMoved();
        $this->assertTargetPath($targetPath);

        $this->ensureDir(dirname($targetPath));

        if (is_string($this->source)) {
            $this->moveFile($this->source, $targetPath);
        } else {
            $this->copyStreamToFile($this->source, $targetPath);
        }

        $this->moved = true;
    }

    /* --------------------------------------------------------------
     * Meta-data
     * ------------------------------------------------------------ */
    public function getSize(): ?int
    {
        if ($this->size !== null && $this->size > 0) {
            return $this->size;
        }

        if (is_string($this->source) && is_file($this->source)) {
            return filesize($this->source) ?: null;
        }

        return $this->source instanceof StreamInterface
            ? $this->source->getSize()
            : null;
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

    /* ==============================================================
     * Internal helpers
     * ============================================================ */
    private function assertOkAndNotMoved(): void
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File was not uploaded successfully');
        }
        if ($this->moved) {
            throw new RuntimeException('Uploaded file has been moved');
        }
    }

    private function assertTargetPath(string $path): void
    {
        if ($path === '') {
            throw new InvalidArgumentException('Target path must be non-empty');
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}");
        }
    }

    /** zero-copy move when possible */
    private function moveFile(string $tmp, string $dest): void
    {
        $ok = is_uploaded_file($tmp)
            ? move_uploaded_file($tmp, $dest)
            : rename($tmp, $dest);

        if (!$ok) {
            throw new RuntimeException("Failed to move uploaded file to {$dest}");
        }
    }

    /** stream → file copy */
    private function copyStreamToFile(StreamInterface $stream, string $path): void
    {
        $out = fopen($path, 'wb');
        if (!$out) {
            throw new RuntimeException("Cannot write to {$path}");
        }

        $stream->rewind();
        stream_copy_to_stream($stream->detach(), $out);
        fclose($out);
    }
}
