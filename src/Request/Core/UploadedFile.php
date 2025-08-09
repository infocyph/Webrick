<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Core;

use InvalidArgumentException;
use RuntimeException;

/**
 * Minimal, allocation-aware uploaded-file value object.
 *
 *  • Accepts tmp-path **or** StreamInterface
 *  • Performs zero-copy `move_uploaded_file()` / `rename()` whenever possible
 *  • Lazily wraps a tmp-path in a Stream *only* when getStream() is called
 *  • Throws RuntimeException on every failure – never returns false
 */
final class UploadedFile
{
    /** @var string|Stream */
    private string|Stream $src;

    private readonly ?int    $size;
    private readonly int     $err;
    private readonly ?string $clientName;
    private readonly ?string $clientType;

    private bool $moved = false;

    /* ─────────────────────────── ctor ─────────────────────────── */

    /**
     * @param string|Stream $src  tmp filename or stream
     * @param int|null               $size bytes (0 / null ⇒ auto)
     * @param int                    $err  UPLOAD_ERR_* constant
     */
    public function __construct(
        string|Stream $src,
        ?int   $size          = null,
        int    $err           = UPLOAD_ERR_OK,
        ?string $clientName   = null,
        ?string $clientType   = null,
    ) {
        if (!is_string($src) && !$src instanceof Stream) {
            throw new InvalidArgumentException('Source must be filepath or StreamInterface');
        }
        if ($err < 0 || $err > 8) {
            throw new InvalidArgumentException('Invalid upload error code');
        }

        $this->src        = $src;
        $this->size       = $size;
        $this->err        = $err;
        $this->clientName = $clientName;
        $this->clientType = $clientType;
    }

    /* ───────────── factory helper for $_FILES spec ─────────────── */

    public static function fromSpec(array $spec): self
    {
        return new self(
            $spec['tmp_name'] ?? '',
            $spec['size']     ?? null,
            $spec['error']    ?? UPLOAD_ERR_NO_FILE,
            $spec['name']     ?? null,
            $spec['type']     ?? null,
        );
    }

    /* ────────────────── StreamInterface proxy ──────────────────── */

    public function getStream(): Stream
    {
        $this->assertOkAndNotMoved();

        if (is_string($this->src)) {
            $h = @fopen($this->src, 'rb');
            if ($h === false) {
                throw new RuntimeException("Cannot open uploaded file: {$this->src}");
            }
            $this->src = new Stream($h);
        }

        return $this->src;
    }

    public function moveTo($targetPath): void
    {
        $this->assertOkAndNotMoved();
        $this->assertTarget($targetPath);
        $this->ensureDir(dirname($targetPath));

        if (is_string($this->src)) {
            $ok = is_uploaded_file($this->src)
                ? move_uploaded_file($this->src, $targetPath)
                : rename($this->src, $targetPath);
            if (!$ok) {
                throw new RuntimeException("Failed to move uploaded file to {$targetPath}");
            }
        } else {
            $out = fopen($targetPath, 'wb');
            if (!$out) {
                throw new RuntimeException("Cannot write to {$targetPath}");
            }
            $this->src->rewind();
            // copy the whole payload (no length arg)
            stream_copy_to_stream($this->src->detach(), $out, $this->getSize() ?? -1);
            fclose($out);
        }
        $this->moved = true;
    }

    /* ───────────────────── meta-data getters ───────────────────── */

    public function getSize(): ?int
    {
        if ($this->size) {
            return $this->size;
        }
        if (is_string($this->src) && is_file($this->src)) {
            return filesize($this->src) ?: null;
        }
        return $this->src instanceof Stream
            ? $this->src->getSize()
            : null;
    }

    public function getError(): int
    {
        return $this->err;
    }
    public function getClientFilename(): ?string
    {
        return $this->clientName;
    }
    public function getClientMediaType(): ?string
    {
        return $this->clientType;
    }

    /* ───────────────────────── internals ───────────────────────── */

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
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}");
        }
    }
}
