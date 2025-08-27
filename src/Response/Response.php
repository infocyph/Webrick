<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response;

use Infocyph\InterMix\Remix\MacroMix;
use Infocyph\Webrick\Constants\Status;
use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Constants\MediaType;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\HeaderBag;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Response\Headers\CacheControl;
use Infocyph\Webrick\Response\Headers\ContentDisposition;
use Infocyph\Webrick\Response\Internal\LazyJsonStream;
use Infocyph\Webrick\Response\Internal\Utils;
use Infocyph\Webrick\Response\Negotiation\ContentTypeNegotiator;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Url\SignedUrlGenerator;
use Infocyph\Webrick\Router\Url\TemporaryUrlGenerator;
use Infocyph\Webrick\Router\Url\UrlGenerator;
use JsonSerializable;
use RuntimeException;

class Response
{
    use MacroMix;

    private HeaderBag $headers;
    private BodyStream $body;
    private static ?UrlGenerator $urlGen = null;
    private static ?SignedUrlGenerator $signedGen = null;
    private static ?TemporaryUrlGenerator $tempGen = null;
    private static ?Collection $routesRef = null;

    /**
     * When non-null, the response is a live stream. The callable MUST return
     * an iterable<string> (e.g., a Generator yielding string chunks) OR a
     * single string for one-shot writes.
     *
     * The emitter will:
     *  - skip auto Content-Length
     *  - optionally add Transfer-Encoding: chunked for HTTP/1.1
     *  - echo/flush each yielded chunk
     *
     * @var null|\Closure(): iterable<string>|string
     */
    private ?\Closure $producer = null;

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
       New: streaming helper (no Content-Length, emitter streams live)
       -------------------------------------------------------------- */

    /**
     * Create a live streaming response.
     *
     * @param callable|iterable $producer
     *        Callable returning an iterable of chunks, or a single string; OR an iterable directly.
     * @param int $status
     * @param array $headers
     * @return Response
     */
    public static function stream(
        callable|iterable $producer,
        int $status = 200,
        array $headers = [],
    ): self {
        // Don’t let callers accidentally pin a length
        unset($headers['Content-Length'], $headers['content-length']);

        // Sensible streaming defaults (override as desired)
        $headers = [
                'Cache-Control' => $headers['Cache-Control'] ?? 'no-store',
                'X-Accel-Buffering' => $headers['X-Accel-Buffering'] ?? 'no', // helps with Nginx proxy buffering
            ] + $headers;

        $resp = new self($status, new Stream(''), $headers);
        $resp->producer = self::normalizeProducer($producer);
        return $resp;
    }

    /** True when this response will be streamed by the emitter. */
    public function isStreaming(): bool
    {
        return $this->producer !== null;
    }

    /**
     * Internal use by emitter.
     * @return null|\Closure(): iterable<string>|string
     */
    public function getProducer(): ?\Closure
    {
        return $this->producer;
    }

    /** Convert a callable/iterable into a normalized closure. */
    private static function normalizeProducer(callable|iterable $producer): \Closure
    {
        if (is_iterable($producer)) {
            return static fn() => $producer;
        }

        // callable()
        return static function () use ($producer) {
            $out = $producer();
            if ($out instanceof \Generator || is_iterable($out)) {
                return $out;
            }
            // Treat anything else as a one-shot string (including '')
            return $out === null ? [] : [$out];
        };
    }

    /* --------------------------------------------------------------
       JSON + Redirect helpers (unchanged)
       -------------------------------------------------------------- */

    public static function plaintext(string $msg, int $code = 400, array $headers = []): self
    {
        $headers = ['Content-Type' => $headers['Content-Type'] ?? 'text/plain; charset=utf-8'] + $headers;
        return new self($code, new Stream($msg), $headers);
    }

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

    public static function auto(
        Request $r,
        callable|array|object|string|int|float|bool|null $data,
        int $status = 200,
        array $headers = [],
        int $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        int $depth = 512,
    ): self {
        // Ask the request which of these it prefers (client Accept order wins).
        // We include "+json" so "application/*+json" is recognized.
        $want = ContentTypeNegotiator::chooseFromRequest($r, ['application/json', '+json', 'text/plain'])
            ?? 'application/json';

        // JSON path (includes "+json" like application/*+json)
        if ($want === 'application/json' || str_ends_with($want, '+json')) {
            return self::json($data, $status, $headers, $flags, $depth);
        }

        // Plain text path
        if (is_string($data) || is_scalar($data) || $data === null) {
            return self::plaintext((string) $data, $status, $headers);
        }

        // Complex payload but client prefers text: serialize to JSON string,
        // serve as text/plain (readable + unambiguous).
        $payload = $data instanceof \JsonSerializable ? $data->jsonSerialize() : $data;
        $json = \json_encode($payload, $flags, $depth);
        if ($json === false) {
            throw new \RuntimeException('JSON encode error: ' . \json_last_error_msg());
        }
        $headers = ['Content-Type' => $headers['Content-Type'] ?? 'text/plain; charset=utf-8'] + $headers;
        return new self($status, new Stream($json), $headers);
    }

    public static function redirect(string $uri, int $status = 302): self
    {
        $s = Status::tryFrom($status);
        if (!$s || !$s->isRedirect()) {
            throw new \InvalidArgumentException('Redirect status must be a 3xx code.');
        }

        return new self($status, new Stream(''))
            ->withSmartHeader('Location', $uri)
            ->withHeader('Cache-Control', 'no-store')
            ->withoutHeader('Content-Type')
            ->withoutHeader('Content-Length');
    }

    /**
     * One-time binding done by Registrar’s constructor.
     * No base URL here — generators build *relative* paths.
     */
    public static function bindUrlServices(
        Collection $routes,
        ?string $signKey = null,
        ?int $defaultTtl = null,
    ): void {
        self::$routesRef = $routes;
        self::$urlGen = new UrlGenerator('', $routes);

        if ($signKey !== null && $signKey !== '') {
            self::$signedGen = new SignedUrlGenerator('', $routes, $signKey);
            if ($defaultTtl !== null) {
                self::$tempGen = new TemporaryUrlGenerator('', $routes, $signKey, $defaultTtl);
            }
        }
    }

    /* ───────────────── URL helpers you call from handlers ───────────── */

    public static function urlFor(
        string $name,
        array $params = [],
        array $query = [],
        bool $absolute = false,
    ): string {
        self::assertUrlBound();
        $path = self::$urlGen->urlFor($name, $params, $query, false);
        return $absolute ? self::withRouteDomain($name, $path) : $path;
    }

    public static function signedUrlFor(
        string $name,
        array $params = [],
        array $query = [],
        ?int $ttl = null,
        bool $absolute = false,
    ): string {
        self::assertSignedBound();

        // Build signed *relative* URL first
        if ($ttl === null) {
            // no TTL -> SignedUrlGenerator::signed with null TTL
            $path = self::$signedGen->signed($name, $params, $query, null, false);
        } else {
            if ($ttl < 1) {
                throw new \InvalidArgumentException('TTL must be >= 1');
            }
            $path = self::$signedGen->signed($name, $params, $query, $ttl, false);
        }

        return $absolute ? self::withRouteDomain($name, $path) : $path;
    }

    public static function temporaryUrlFor(
        string $name,
        array $params = [],
        array $query = [],
        bool $absolute = false,
    ): string {
        if (!self::$tempGen) {
            throw new \LogicException('TemporaryUrlGenerator not bound (no default TTL provided).');
        }
        $path = self::$tempGen->temporary($name, $params, $query, null, false);
        return $absolute ? self::withRouteDomain($name, $path) : $path;
    }

    /* ───────────────── private helpers ───────────────── */

    private static function assertUrlBound(): void
    {
        if (!self::$urlGen || !self::$routesRef) {
            throw new \LogicException('URL services not bound. Enable via Registrar constructor.');
        }
    }

    private static function assertSignedBound(): void
    {
        if (!self::$signedGen || !self::$routesRef) {
            throw new \LogicException('Signed URL service not bound. Provide $signKey to Registrar.');
        }
    }

    /** Prefix with the route’s own domain (protocol-relative) when present. */
    private static function withRouteDomain(string $name, string $path): string
    {
        $route = self::$routesRef->findByName($name);
        $domain = $route?->getDomain();

        return ($domain && $domain !== '*') ? ('//' . $domain . $path) : $path;
    }

    /**
     * Attachment / download helper.
     *
     * @param string|Stream $file local path **or** pre-built stream
     * @param string $name final filename shown to the client
     * @param string|null $mime explicit mime, otherwise inferred
     * @param array $headers extra headers (caller wins on conflict)
     */
    public static function attachment(
        string|Stream $file,
        string $name,
        ?string $mime = null,
        array $headers = [],
    ): self {
        $stream = self::streamFor($file);
        [$size, $mtime] = self::metaFor($file);
        $size ??= $stream->getSize();
        $mtime ??= self::mtimeFromStream($stream);
        $mime = self::inferMime($name, $mime);
        $defaults = self::baseDownloadHeaders($name, $mime);

        // Fill common headers only when caller didn't provide them
        self::putIfAbsent($defaults, 'Content-Length', self::chooseLength($file, $stream, $size), $headers);
        self::putIfAbsent($defaults, 'Last-Modified', self::formatHttpDate($mtime), $headers);
        self::putIfAbsent($defaults, 'ETag', self::etagFromMeta($size, $mtime, $name), $headers);

        return new self(200, $stream, $defaults + $headers);
    }

    public static function inline(
        string|Stream $file,
        ?string $name = null,
        ?string $mime = null,
        array $headers = [],
    ): self {
        $name ??= is_string($file) ? basename($file) : 'inline';
        $stream = $file instanceof Stream ? $file : self::openFileStream($file);
        $mime ??= MediaType::fromFilename($name)->value;

        $defaults = [
            'Content-Type' => $mime,
            'Content-Disposition' => ContentDisposition::inline($name),
        ];
        if ($stream->getSize() !== null && !isset($headers['Content-Length'])) {
            $defaults['Content-Length'] = (string)$stream->getSize();
        }
        return new self(200, $stream, $defaults + $headers);
    }

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
       PSR-7-ish surface (kept as-is; withBody clears producer)
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
        return $this->headers->getHeaderLine($n);
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
        $x = $this->copy(body: $b);
        // If caller replaces the body, this is no longer a live stream.
        $x->producer = null;
        return $x;
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

    private static function mtimeFromStream(Stream $stream): ?int
    {
        $uri = $stream->getMetadata('uri');
        if (is_string($uri) && $uri !== '' && @is_file($uri)) {
            return @filemtime($uri) ?: null;
        }
        return null;
    }

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
        // keep $x->producer as-is; specific methods (withBody) may clear it.
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

    private static function streamFor(string|Stream $file): Stream
    {
        return $file instanceof Stream ? $file : self::openFileStream($file);
    }

    private static function metaFor(string|Stream $file): array
    {
        if (!is_string($file)) {
            return [null, null];
        }
        $size = @filesize($file) ?: null;
        $mtime = @filemtime($file) ?: null;
        return [$size, $mtime];
    }

    private static function inferMime(string $name, ?string $explicit): string
    {
        return $explicit ?? MediaType::fromFilename($name)->value;
    }

    private static function baseDownloadHeaders(string $name, string $mime): array
    {
        return [
            'Content-Type' => $mime,
            'Content-Disposition' => ContentDisposition::attachment($name),
        ];
    }

    private static function chooseLength(string|Stream $file, Stream $stream, ?int $fsSize): ?string
    {
        $len = is_string($file) ? $fsSize : ($stream->getSize() ?? null);
        return $len !== null ? (string)$len : null;
    }

    private static function formatHttpDate(?int $mtime): ?string
    {
        return $mtime ? gmdate('D, d M Y H:i:s', $mtime) . ' GMT' : null;
    }

    private static function etagFromMeta(?int $size, ?int $mtime, string $name): ?string
    {
        if ($size === null && $mtime === null) {
            return null;
        }
        $seed = ($size ?? -1) . '|' . ($mtime ?? -1) . '|' . $name;
        return Utils::generateEtag($seed);
    }

    private static function putIfAbsent(array &$target, string $name, ?string $value, array $caller): void
    {
        if ($value !== null && !array_key_exists($name, $caller)) {
            $target[$name] = $value;
        }
    }
}
