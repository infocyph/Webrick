<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response;

use Infocyph\InterMix\Remix\MacroMix;
use Infocyph\Webrick\Constants\Status;
use Infocyph\Webrick\Response\Constants\Mime;
use Infocyph\Webrick\Request\Support\HeaderBag;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Response\Headers\CacheControl;
use Infocyph\Webrick\Response\Internal\LazyJsonStream;
use JsonSerializable;
use RuntimeException;

/**
 * Ultra-lean immutable PSR-7 Response with a few *built-in*
 * Laravel-style factory helpers (json / redirect / attachment).
 *
 * - Zero reflection, zero magic setters.
 * - All operations clone – no accidental mutation.
 * - Helpers allocate only what they absolutely need.
 */
class Response
{
    use MacroMix;

    // still handy for custom macros

    /* ---------------------------------------------------------------------
       0)  Core state
       ------------------------------------------------------------------- */
    private HeaderBag $headers;
    private Stream $body;

    public function __construct(
        private int $statusCode = 200,
        Stream|string|null $body = null,
        array $headers = [],
        private string $protocolVersion = '1.1',
        private ?string $reasonPhrase = null,
    ) {
        $this->headers = new HeaderBag($headers);
        $this->body = $body instanceof Stream ? $body : new Stream($body ?? '');
        $this->reasonPhrase ??= self::statusText($this->statusCode);
    }

    /* ---------------------------------------------------------------------
       1)  Static SHORTCUTS  (no runtime registration)
       ------------------------------------------------------------------- */

    /** JSON payload helper (`return Response::json($data, 201)` ) */
    /* =========================================================
 * Response::json()
 * =======================================================*/
    public static function json(
        callable|array|object $data,
        int $status = 200,
        array $headers = [],
        int $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        int $depth = 512,
    ): self {
        $headers += ['Content-Type' => 'application/json; charset=utf-8'];

        /* 1️⃣  Fast-path for small, plain payloads
         *     – encode once and inline when the final blob ≤ 32 KiB           */
        if (!\is_callable($data) && !$data instanceof JsonSerializable) {
            $json = \json_encode($data, $flags, $depth);
            if ($json === false) {
                throw new RuntimeException('JSON encode error: ' . \json_last_error_msg());
            }

            if (\strlen($json) <= 32 * 1024) {          // ≤ 32 KiB  → eager
                return new self($status, new Stream($json), $headers);
            }
            /* >32 KiB – fall through to the lazy path (will re-encode once).
             * Keeping the streaming behaviour avoids an in-memory copy. */
        }

        /* 2️⃣  Lazy path – postpone encoding until the body is actually read */
        $stream = new LazyJsonStream($data, $flags, $depth);
        return new self($status, $stream, $headers);
    }

    /** Redirect helper (`return Response::redirect('/login')`) */
    public static function redirect(
        string $uri,
        int $status = 302
    ): self {
        // RFC-compliant status codes only
        if ($status < 300 || $status > 399) {
            throw new \InvalidArgumentException('Redirect status must be a 3xx code.');
        }

        return new self($status, new Stream(''))
            ->withSmartHeader('Location', $uri)
            ->withoutHeader('Content-Type')              // 3xx responses don’t need it
            ->withoutHeader('Content-Length');           // length is implicitly 0
    }

    /**
     * Attachment / download helper.
     *
     * @param string|Stream $file local path **or** pre-built stream
     */
    public static function attachment(
        string|Stream $file,
        string $name,
        string $mime = 'application/octet-stream',
        array $headers = [],
    ): self {
        if (\is_string($file)) {
            $stream = new Stream(\fopen($file, 'rb'));

            // Skip stat() when the length is already supplied
            $len = array_key_exists('Content-Length', $headers)
                ? null
                : (\filesize($file) ?: null);
        } else {
            $stream = $file;
            $len = $stream->getSize();
        }

        $safeName = addcslashes($name, "\"\r\n\\");
        $headers += [
            'Content-Type' => $mime,
            'Content-Disposition' => "attachment; filename=\"$safeName\"; filename*=UTF-8''" . rawurlencode($name),
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

    public function withSmartHeader(string $name, string $value): self
    {
        return $this->copy(
            headers: $this->headers->withSmart($name, $value)
        );
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

    public function getBody(): Stream
    {
        return $this->body;
    }

    public function withBody(Stream $b): self
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
            'Content-Type' => '',
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
        ?Stream $body = null,
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
        string|Stream $file,
        ?string $name = null,
        array $headers = [],
        ?string $mime = null,
    ): self {
        $name = $name ? addcslashes($name, "\"\r\n\\") : $name;
        if ($name === null && \is_string($file)) {
            $name = basename($file);
        }
        $mime ??= Mime::fromExtension(pathinfo((string)$name, \PATHINFO_EXTENSION));

        return self::attachment($file, $name ?? 'download', $mime, $headers);
    }


    /* -----------------------------------------------------------------
 * Extra convenience helpers  – zero-cost unless you call them
 * ---------------------------------------------------------------- */

    /* 1. Symfony-style factory  --------------------------------------- */
    public static function create(
        string $content = '',
        int $status = 200,
        array $headers = [],
    ): self {
        return new self($status, $content, $headers);
    }

    /* 2. sendFile / streamDownload (Laravel’s alias of download()) ---- */
    public static function streamDownload(
        string|Stream $file,
        ?string $name = null,
        string $mime = 'application/octet-stream',
        array $headers = [],
    ): self {
        if (\is_string($file)) {
            $stream = new Stream(\fopen($file, 'rb'));
            $len = \filesize($file) ?: null;
            $name ??= \basename($file);
        } else {
            $stream = $file;
            $len = $stream->getSize();
            $name ??= 'download';
        }

        $safe = \addcslashes($name, '"\\');
        $headers += [
            'Content-Type' => $mime,
            'Content-Disposition' => "attachment; filename=\"{$safe}\"; filename*=UTF-8''" .
                \rawurlencode($name),
        ];
        if ($len !== null) {
            $headers['Content-Length'] = (string)$len;
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
    public function cache(): CacheControl
    {
        return CacheControl::fromHeaderBag($this->headers);
    }

    /**
     * Apply a mutation to Cache-Control in one go:
     *
     *     $resp = $resp->withCache(fn($cc) => $cc->public()->maxAge(60));
     */
    public function withCache(\Closure $edit): self
    {
        $cc = $edit($this->cache());
        return $this->withHeader('Cache-Control', (string)$cc);
    }

}
