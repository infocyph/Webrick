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
    private readonly int $err;

    private bool $moved = false;

    /**
     * Constructs a new UploadedFile value object.
     *
     * @param string|Stream $src Either a tmp-path or a StreamInterface
     * @param int|null $size Bytes; null enables automatic detection
     * @param int $err UPLOAD_ERR_* constant
     * @param string|null $clientName Client-provided filename
     * @param string|null $clientType Client-provided MIME type
     *
     * @throws InvalidArgumentException If the source is neither a filepath nor a StreamInterface,
     *                                  or if the error code is invalid.
     */
    public function __construct(
        private string|Stream $src,
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
     * Creates an UploadedFile from a $_FILES-style specification array.
     *
     * The following keys are supported in the $spec array:
     *   - 'tmp_name': string, tmp filename
     *   - 'size': int|null, bytes (null ⇒ auto)
     *   - 'error': int, UPLOAD_ERR_* constant
     *   - 'name': string|null, client-provided filename
     *   - 'type': string|null, client-provided MIME type
     *
     * If any of the above keys are missing, the following default values will be used:
     *   - 'tmp_name': empty string
     *   - 'size': null
     *   - 'error': UPLOAD_ERR_NO_FILE
     *   - 'name': null
     *   - 'type': null
     *
     * @param array{
     *   tmp_name?: string,
     *   size?: int|null,
     *   error?: int,
     *   name?: string|null,
     *   type?: string|null
     * } $spec $_FILES-style specification array
     */
    public static function fromSpec(array $spec): self
    {
        $tmpName = \is_string($spec['tmp_name'] ?? null) ? $spec['tmp_name'] : '';
        $size = \is_int($spec['size'] ?? null) ? $spec['size'] : null;
        $error = \is_int($spec['error'] ?? null) ? $spec['error'] : UPLOAD_ERR_NO_FILE;
        $name = \is_string($spec['name'] ?? null) ? $spec['name'] : null;
        $type = \is_string($spec['type'] ?? null) ? $spec['type'] : null;

        return new self(
            $tmpName,
            $size,
            $error,
            $name,
            $type,
        );
    }

    /**
     * Get the original filename of the uploaded file as sent in the request.
     *
     * This value is available from the $_FILES superglobal and can be used to
     * determine the original filename of the uploaded file.
     *
     * @return string|null The original filename of the uploaded file, or null if not available.
     */
    public function getClientFilename(): ?string
    {
        return $this->clientName;
    }

    /**
     * Get the media type of the uploaded file as sent in the request.
     *
     * This value is available from the $_FILES superglobal and can be used to
     * determine the MIME type of the uploaded file.
     *
     * @return string|null The media type of the uploaded file, or null if not available.
     */
    public function getClientMediaType(): ?string
    {
        return $this->clientType;
    }

    /**
     * Return the error code associated with the uploaded file.
     *
     * @return int The error code associated with the uploaded file.
     *             One of the UPLOAD_ERR_* constants.
     *
     * @see https://www.php.net/manual/en/features.file-upload.errors.php
     */
    public function getError(): int
    {
        return $this->err;
    }

    /**
     * Return the size of the uploaded file in bytes.
     *
     * If the size is known, it will be returned. Otherwise, it will
     * attempt to determine the size based on the underlying stream
     * or file.
     *
     * @return int|null The size of the uploaded file in bytes, or null if unknown.
     */
    public function getSize(): ?int
    {
        if ($this->size !== null) {
            return $this->size;
        }
        if (is_string($this->src) && is_file($this->src)) {
            $size = filesize($this->src);

            return $size === false ? null : $size;
        }

        return $this->src instanceof Stream
            ? $this->src->getSize()
            : null;
    }

    /**
     * Return a PSR-7 Stream for the uploaded file.
     *
     * @return Stream A PSR-7 Stream representing the uploaded file.
     *
     * @throws RuntimeException If the uploaded file cannot be opened.
     */
    public function getStream(): Stream
    {
        $this->assertOkAndNotMoved();

        if (is_string($this->src)) {
            $h = fopen($this->src, 'rb');
            if ($h === false) {
                throw new RuntimeException("Cannot open uploaded file: {$this->src}");
            }
            $this->src = new Stream($h);
        }

        return $this->src;
    }

    /**
     * Atomically move the uploaded file to the given target path.
     *
     * The target path must be a fully qualified path (not a relative path).
     * The containing directory must exist, otherwise an exception will be thrown.
     *
     * If the uploaded file is a stream, it will be fully copied to the target path.
     * If the uploaded file is a string (i.e. a file path), it will be moved using
     * `move_uploaded_file()` if it's an uploaded file, or `rename()` otherwise.
     *
     * After a successful move, the `moved` property will be set to true.
     *
     * @throws RuntimeException if the move fails for any reason.
     */
    public function moveTo(string $targetPath): void
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
            if ($this->src->isSeekable()) {
                $this->src->rewind();
            }
            $in = $this->src->detach();
            if (!\is_resource($in)) {
                throw new RuntimeException('Uploaded stream handle is detached.');
            }
            $out = fopen($targetPath, 'wb');
            if (!\is_resource($out)) {
                throw new RuntimeException("Cannot write to {$targetPath}");
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
        }
        $this->moved = true;
    }

    /**
     * Throws RuntimeException if the file was not uploaded successfully or
     * if the file has been moved.
     *
     * This method is used internally to ensure that the file is in a valid state
     * before performing any operations on it.
     *
     * @throws RuntimeException
     */
    private function assertOkAndNotMoved(): void
    {
        if ($this->err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File was not uploaded successfully');
        }
        if ($this->moved) {
            throw new RuntimeException('Uploaded file has been moved');
        }
    }

    /**
     * Checks if the given target path is non-empty.
     *
     * @param string $path The target path to check.
     *
     * @throws InvalidArgumentException If the target path is empty.
     */
    private function assertTarget(string $path): void
    {
        if ($path === '') {
            throw new InvalidArgumentException('Target path must be non-empty');
        }
    }

    /**
     * Recursively creates a directory if it does not exist, and throws
     * RuntimeException if it cannot be created.
     *
     * @param string $dir the directory to ensure
     *
     * @throws RuntimeException if the directory cannot be created
     */
    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}");
        }
    }
}
