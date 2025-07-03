<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response;

use Infocyph\InterMix\Remix\MacroMix;

// keep for user-land extensions
use Infocyph\Webrick\Response\Constants\Mime;
use Infocyph\Webrick\Response\Constants\Status;
use Infocyph\Webrick\Response\Internal\HeaderBag;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Ultra-lean immutable PSR-7 Response with a few *built-in*
 * Laravel-style factory helpers (json / redirect / attachment).
 *
 * - Zero reflection, zero magic setters.
 * - All operations clone – no accidental mutation.
 * - Helpers allocate only what they absolutely need.
 */
class Response implements ResponseInterface
{
    use MacroMix;

    // still handy for custom macros

    /* ---------------------------------------------------------------------
       0)  Core state
       ------------------------------------------------------------------- */
    private HeaderBag $headers;
    private StreamInterface $body;

    public function __construct(
        private int $statusCode = 200,
        StreamInterface|string|null $body = null,
        array $headers = [],
        private string $protocolVersion = '1.1',
        private ?string $reasonPhrase = null,
    ) {
        $this->headers = new HeaderBag($headers);
        $this->body = $body instanceof StreamInterface ? $body : new Stream($body ?? '');
        $this->reasonPhrase ??= self::statusText($this->statusCode);
    }

    /* ---------------------------------------------------------------------
       1)  Static SHORTCUTS  (no runtime registration)
       ------------------------------------------------------------------- */

    /** JSON payload helper (`return Response::json($data, 201)` ) */
    public static function json(
        mixed $data,
        int $status = 200,
        array $headers = [],
        int $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        int $depth = 512,
    ): self {
        $json = json_encode($data, $flags, $depth);
        if ($json === false) {
            throw new RuntimeException('JSON encode error: ' . json_last_error_msg());
        }

        $headers += ['Content-Type' => 'application/json; charset=utf-8'];
        return new self($status, new Stream($json), $headers);
    }

    /** Redirect helper (`return Response::redirect('/login')`) */
    public static function redirect(
        string $uri,
        int $status = 302,
        array $headers = [],
    ): self {
        if ($status < 300 || $status > 399) {
            throw new RuntimeException("Redirect status must be 3xx; {$status} given.");
        }
        $headers['Location'] = $uri;
        return new self($status, new Stream(''), $headers);
    }

    /**
     * Attachment / download helper.
     *
     * @param string|StreamInterface $file local path **or** pre-built stream
     */
    public static function attachment(
        string|StreamInterface $file,
        string $name,
        string $mime = 'application/octet-stream',
        array $headers = [],
    ): self {
        if (is_string($file)) {
            $stream = new Stream(fopen($file, 'rb'));
            $len = filesize($file) ?: null;
        } else {
            $stream = $file;
            $len = $stream->getSize();
        }

        $safeName = addcslashes($name, '"\\');
        $headers += [
            'Content-Type' => $mime,
            'Content-Disposition' => "attachment; filename=\"{$safeName}\"; filename*=UTF-8''" . rawurlencode($name),
        ];
        if ($len !== null) {
            $headers['Content-Length'] = (string)$len;
        }

        return new self(200, $stream, $headers);
    }

    /* ---------------------------------------------------------------------
       2)  PSR-7 MessageInterface
       ------------------------------------------------------------------- */
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

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $b): self
    {
        return $this->copy(body: $b);
    }

    /* ---------------------------------------------------------------------
       3)  PSR-7 ResponseInterface
       ------------------------------------------------------------------- */
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

    /** Convenience when you need a body-less reply (e.g. 204, 304, 412). */
    public static function empty(int $code, array $headers = []): self
    {
        // start with an explicit zero-length body
        $resp = new self($code, new Stream(''), [
            'Content-Length' => '0',
            'Content-Type'   => '',
        ]);

        // merge any caller-supplied headers (ETag, Last-Modified, …)
        foreach ($headers as $name => $value) {
            $resp = $resp->withHeader($name, $value);
        }

        return $resp;
    }

    /* ---------------------------------------------------------------------
       4)  Internals
       ------------------------------------------------------------------- */
    private static function statusText(int $code): string
    {
        return Status::text($code) ?? '';
    }

    /** Internal named-arg clone helper – avoids dozens of small withXYZ()s */
    private function copy(
        ?int $statusCode = null,
        ?HeaderBag $headers = null,
        ?StreamInterface $body = null,
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

    /**
     * download() – thin alias of attachment(), signature matches Laravel:
     *   Response::download($path, $name = null, array $headers = [], ?string $mime = null)
     */
    public static function download(
        string|StreamInterface $file,
        ?string $name = null,
        array $headers = [],
        ?string $mime = null,
    ): self {
        if ($name === null && \is_string($file)) {
            $name = basename($file);
        }
        $mime ??= Mime::fromExtension(
            pathinfo((string)$name, \PATHINFO_EXTENSION),
        );
        return self::attachment($file, $name ?? 'download', $mime, $headers);
    }

    /* -----------------------------------------------------------------
 * Extra convenience helpers  – zero-cost unless you call them
 * ---------------------------------------------------------------- */

    /* 1. Symfony-style factory  --------------------------------------- */
    public static function create(
        string $content = '',
        int    $status  = 200,
        array  $headers = []
    ): self {
        return new self($status, $content, $headers);
    }

    /* 2. sendFile / streamDownload (Laravel’s alias of download()) ---- */
    public static function streamDownload(
        string|\Psr\Http\Message\StreamInterface $file,
        ?string                                  $name    = null,
        string                                   $mime    = 'application/octet-stream',
        array                                    $headers = [],
    ): self {
        if (\is_string($file)) {
            $stream = new Stream(\fopen($file, 'rb'));
            $len    = \filesize($file) ?: null;
            $name ??= \basename($file);
        } else {
            $stream = $file;
            $len    = $stream->getSize();
            $name ??= 'download';
        }

        $safe = \addcslashes($name, '"\\');
        $headers += [
            'Content-Type'        => $mime,
            'Content-Disposition' => "attachment; filename=\"{$safe}\"; filename*=UTF-8''" .
                \rawurlencode($name),
        ];
        if ($len !== null) {
            $headers['Content-Length'] = (string) $len;
        }

        // same semantics as attachment() but streams are already given
        return new self(200, $stream, $headers);
    }

    /* 3. noContent() – tight alias around ::empty(204) ---------------- */
    public static function noContent(array $headers = []): self
    {
        return self::empty(204, $headers);
    }

    /* 4. Cache-Control fluent helper  -------------------------------- */

    /** Read the current Cache-Control header into a mutable builder */
    public function cache(): \Infocyph\Webrick\Response\Headers\CacheControl
    {
        return \Infocyph\Webrick\Response\Headers\CacheControl::fromHeaderBag($this->headers);
    }

    /**
     * Apply a mutation to Cache-Control in one go:
     *
     *     $resp = $resp->withCache(fn($cc) => $cc->public()->maxAge(60));
     */
    public function withCache(\Closure $edit): self
    {
        $cc = $edit($this->cache());
        return $this->withHeader('Cache-Control', (string) $cc);
    }

}
