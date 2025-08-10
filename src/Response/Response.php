<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response;

use Infocyph\InterMix\Remix\MacroMix;
use Infocyph\Webrick\Constants\Status;
use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Constants\MediaType;
use Infocyph\Webrick\Request\Support\HeaderBag;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Response\Headers\CacheControl;
use Infocyph\Webrick\Response\Headers\ContentDisposition;
use Infocyph\Webrick\Response\Internal\LazyJsonStream;
use Infocyph\Webrick\Response\Internal\Utils;
use JsonSerializable;
use RuntimeException;

class Response
{
    use MacroMix;

    private HeaderBag $headers;
    private BodyStream $body;

    public function __construct(
        private int $statusCode = 200,
        BodyStream|string|null $body = null,
        array $headers = [],
        private string $protocolVersion = '1.1',
        private ?string $reasonPhrase = null,
    ) {
        $this->headers = new HeaderBag($headers);
        $this->body = $body instanceof BodyStream ? $body : new Stream($body ?? '');
        $this->reasonPhrase ??= self::statusText($this->statusCode);
    }

    /* --------------------------------------------------------------
       JSON + Redirect helpers (unchanged)
       -------------------------------------------------------------- */

    public static function json(
        callable|array|object|string $data,
        int $status = 200,
        array $headers = [],
        int $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        int $depth = 512,
    ): self {
        $headers += ['Content-Type' => 'application/json; charset=utf-8'];

        if (!\is_callable($data) && !$data instanceof JsonSerializable) {
            $json = \json_encode($data, $flags, $depth);
            if ($json === false) {
                throw new RuntimeException('JSON encode error: ' . \json_last_error_msg());
            }
            if (\strlen($json) <= 32 * 1024) {
                return new self($status, new Stream($json), $headers);
            }
        }

        $stream = new LazyJsonStream($data, $flags, $depth);
        return new self($status, $stream, $headers);
    }

    public static function redirect(string $uri, int $status = 302): self
    {
        if ($status < 300 || $status > 399) {
            throw new \InvalidArgumentException('Redirect status must be a 3xx code.');
        }

        return new self($status, new Stream(''))
            ->withSmartHeader('Location', $uri)
            ->withoutHeader('Content-Type')
            ->withoutHeader('Content-Length');
    }

    /**
     * Attachment / download helper.
     *
     * @param string|Stream $file local path **or** pre-built stream
     * @param string        $name final filename shown to the client
     * @param string|null   $mime explicit mime, otherwise inferred
     * @param array         $headers extra headers (caller wins on conflict)
     */
    public static function attachment(
        string|Stream $file,
        string $name,
        ?string $mime = null,
        array $headers = [],
    ): self {
        $stream         = self::streamFor($file);
        [$size, $mtime] = self::metaFor($file);
        $mime           = self::inferMime($name, $mime);
        $defaults       = self::baseDownloadHeaders($name, $mime);

        // Fill common headers only when caller didn't provide them
        self::putIfAbsent($defaults, 'Content-Length', self::chooseLength($file, $stream, $size), $headers);
        self::putIfAbsent($defaults, 'Last-Modified', self::formatHttpDate($mtime), $headers);
        self::putIfAbsent($defaults, 'ETag', self::etagFromMeta($size, $mtime, $name), $headers);

        return new self(200, $stream, $defaults + $headers);
    }

    public static function inline(string|Stream $file, ?string $name = null, ?string $mime = null, array $headers = []): self
    {
        $name ??= is_string($file) ? basename($file) : 'inline';
        $stream = $file instanceof Stream ? $file : self::openFileStream($file);
        $mime ??= MediaType::fromFilename($name)->value;

        $defaults = [
            'Content-Type'        => $mime,
            'Content-Disposition' => ContentDisposition::inline($name),
        ];
        if ($stream->getSize() !== null && !isset($headers['Content-Length'])) {
            $defaults['Content-Length'] = (string)$stream->getSize();
        }
        return new self(200, $stream, $defaults + $headers);
    }

    /**
     * download() – Laravel-style alias:
     *   Response::download($fileOrStream, $name = null, array $headers = [], ?string $mime = null)
     */
    public static function download(
        string|Stream $file,
        ?string $name = null,
        array $headers = [],
        ?string $mime = null,
    ): self {
        if ($name === null) {
            $name = is_string($file) ? basename($file) : 'download';
        }
        return self::attachment($file, $name, $mime, $headers);
    }

    /* --------------------------------------------------------------
       PSR-7 getters/setters (unchanged)
       -------------------------------------------------------------- */

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion($v): self
    {
        return $this->copy(protocolVersion: (string)$v);
    }

    public function getHeaders(): array
    {
        return $this->headers->all();
    }

    public function hasHeader($n): bool
    {
        return $this->headers->has($n);
    }

    public function getHeader($n): array
    {
        return $this->headers->get($n);
    }

    public function getHeaderLine($n): string
    {
        return $this->headers->line($n);
    }

    public function withSmartHeader(string $name, string $value): self
    {
        return $this->copy(headers: $this->headers->withSmart($name, $value));
    }

    public function withHeader($n, $v): self
    {
        return $this->copy(headers: $this->headers->with($n, $v));
    }

    public function withAddedHeader($n, $v): self
    {
        return $this->copy(headers: $this->headers->withAdded($n, $v));
    }

    public function withoutHeader($n): self
    {
        return $this->copy(headers: $this->headers->without($n));
    }

    public function getBody(): BodyStream
    {
        return $this->body;
    }

    public function withBody(BodyStream $b): self
    {
        return $this->copy(body: $b);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase ?? '';
    }

    public function withStatus($code, $reasonPhrase = ''): self
    {
        $code = (int)$code;
        if ($code < 100 || $code > 599) {
            throw new RuntimeException("Invalid HTTP status: {$code}");
        }
        return $this->copy(
            statusCode: $code,
            reasonPhrase: $reasonPhrase !== '' ? $reasonPhrase : self::statusText($code),
        );
    }

    public static function empty(int $code, array $headers = []): self
    {
        $resp = new self($code, new Stream(''), ['Content-Length' => '0']);
        foreach ($headers as $name => $value) {
            $resp = $resp->withHeader($name, $value);
        }
        return $resp;
    }

    /* -------------------------------------------------------------- */

    private static function statusText(int $code): string
    {
        return Status::text($code) ?? '';
    }

    private function copy(
        ?int $statusCode = null,
        ?HeaderBag $headers = null,
        ?BodyStream $body = null,
        ?string $protocolVersion = null,
        ?string $reasonPhrase = null,
    ): self {
        $x = clone $this;
        $x->statusCode = $statusCode ?? $this->statusCode;
        $x->headers = $headers ?? clone $this->headers;
        $x->body = $body ?? $this->body;
        $x->protocolVersion = $protocolVersion ?? $this->protocolVersion;
        $x->reasonPhrase = $reasonPhrase ?? $this->reasonPhrase;
        return $x;
    }

    /** Helper: open a file stream with sensible errors. */
    private static function openFileStream(string $file): Stream
    {
        $h = @fopen($file, 'rb');
        if ($h === false) {
            throw new RuntimeException("Unable to open file for download: {$file}");
        }
        return new Stream($h);
    }

    /* ---- optional extras you already had (left intact) -------------- */

    public static function create(string $content = '', int $status = 200, array $headers = []): self
    {
        return new self($status, $content, $headers);
    }

    public static function streamDownload(
        string|Stream $file,
        ?string $name = null,
        string $mime = 'application/octet-stream',
        array $headers = [],
    ): self {
        if (\is_string($file)) {
            $stream = self::openFileStream($file);
            $len = @filesize($file) ?: null;
            $name ??= \basename($file);
        } else {
            $stream = $file;
            $len = $stream->getSize();
            $name ??= 'download';
        }

        $headers += [
            'Content-Type' => $mime,
            'Content-Disposition' => ContentDisposition::attachment($name),
        ];
        if ($len !== null) {
            $headers['Content-Length'] = (string)$len;
        }

        return new self(200, $stream, $headers);
    }

    public static function noContent(array $headers = []): self
    {
        return self::empty(204, $headers);
    }

    public function cache(): CacheControl
    {
        return CacheControl::fromHeaderBag($this->headers);
    }

    public function withCache(\Closure $edit): self
    {
        $cc = $edit($this->cache());
        return $this->withHeader('Cache-Control', (string)$cc);
    }

    /* --------------------------------------------------------------
       Low-complexity helpers for attachment()
       -------------------------------------------------------------- */

    /** Open a stream from path or return the given stream as-is. */
    private static function streamFor(string|Stream $file): Stream
    {
        return $file instanceof Stream ? $file : self::openFileStream($file);
    }

    /** Return [size|null, mtime|null] only when `$file` is a path. */
    private static function metaFor(string|Stream $file): array
    {
        if (!is_string($file)) {
            return [null, null];
        }
        $size  = @filesize($file) ?: null;
        $mtime = @filemtime($file) ?: null;
        return [$size, $mtime];
    }

    /** Decide the MIME type (explicit wins, else by filename). */
    private static function inferMime(string $name, ?string $explicit): string
    {
        return $explicit ?? MediaType::fromFilename($name)->value;
    }

    /** Minimal base headers every download should have. */
    private static function baseDownloadHeaders(string $name, string $mime): array
    {
        return [
            'Content-Type'        => $mime,
            'Content-Disposition' => ContentDisposition::attachment($name),
        ];
    }

    /** Prefer filesystem size when we got a path, else stream length. */
    private static function chooseLength(string|Stream $file, Stream $stream, ?int $fsSize): ?string
    {
        $len = is_string($file) ? $fsSize : ($stream->getSize() ?? null);
        return $len !== null ? (string) $len : null;
    }

    /** HTTP-date or null. */
    private static function formatHttpDate(?int $mtime): ?string
    {
        return $mtime ? gmdate('D, d M Y H:i:s', $mtime) . ' GMT' : null;
    }

    /** Strong ETag from stable file-ish metadata, or null. */
    private static function etagFromMeta(?int $size, ?int $mtime, string $name): ?string
    {
        if ($size === null && $mtime === null) {
            return null;
        }
        $seed = ($size ?? -1) . '|' . ($mtime ?? -1) . '|' . $name;
        return Utils::generateEtag($seed);
    }

    /**
     * Conditionally write a header into $target when value is non-null
     * and absent from caller-supplied $caller.
     */
    private static function putIfAbsent(array &$target, string $name, ?string $value, array $caller): void
    {
        if ($value !== null && !array_key_exists($name, $caller)) {
            $target[$name] = $value;
        }
    }
}
