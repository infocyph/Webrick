<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\Remix\MacroMix;
use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\HeaderBag;
use Infocyph\Webrick\Response\Headers\CacheControl;
use Infocyph\Webrick\Response\Headers\ContentDisposition;
use Infocyph\Webrick\Response\Internal\LazyJsonStream;
use Infocyph\Webrick\Response\Internal\Utils;
use Infocyph\Webrick\Response\Negotiation\ContentTypeNegotiator;
use Infocyph\Webrick\Response\Range\RangeResponder;
use Infocyph\Webrick\Response\View\ViewFactoryInterface;
use JsonSerializable;
use RuntimeException;

class Response
{
    use MacroMix;

    private BodyStream $body;

    private HeaderBag $headers;

    /** @var null|\Closure(): (iterable<string>|string) */
    private ?\Closure $producer = null;

    /**
     * Construct a Response.
     *
     * - $statusCode is the HTTP status.
     * - $body may be a BodyStream, a string, or null.
     * - $headers is a name => value map.
     * - $protocolVersion and $reasonPhrase are optional.
     *
     * @param int $statusCode HTTP status code (default 200)
     * @param BodyStream|string|null $body Body stream or string content
     * @param array<string, string|list<string>> $headers Initial headers (name => value)
     * @param string $protocolVersion HTTP protocol version
     * @param string|null $reasonPhrase Optional reason phrase
     */
    public function __construct(
        private int $statusCode = StatusEnum::OK->value,
        BodyStream|string|null $body = null,
        array $headers = [],
        private string $protocolVersion = '1.1',
        private ?string $reasonPhrase = null,
    ) {
        $this->headers = new HeaderBag(self::headerMap($headers));
        $this->body = $body instanceof BodyStream ? $body : new Stream($body ?? '');
        $this->reasonPhrase ??= self::statusText($this->statusCode);
    }

    /**
     * Create an attachment/download response.
     *
     * - Prepares headers (Content-Length, Last-Modified, ETag) when available.
     *
     * @param string|Stream $file File path or Stream
     * @param string $name Filename provided to client
     * @param string|null $mime Optional MIME type
     * @param array<string, string|list<string>> $headers Additional headers
     * @return self Attachment Response
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

        self::putIfAbsent($defaults, 'Content-Length', self::chooseLength($file, $stream, $size), $headers);
        self::putIfAbsent($defaults, 'Last-Modified', self::formatHttpDate($mtime), $headers);
        self::putIfAbsent($defaults, 'ETag', self::etagFromMeta($size, $mtime, $name), $headers);

        return new self(StatusEnum::OK->value, $stream, self::headerMap($defaults + $headers));
    }

    /**
     * Auto-select response form (JSON or plaintext) based on request negotiation.
     *
     * - Chooses a content type using the request Accept header.
     * - Returns JSON, plaintext, or JSON-as-plain depending on client preference.
     *
     * @param Request $r Request object to inspect Accept preferences
     * @param array<string, mixed>|JsonSerializable|string|int|float|bool|null $data Payload to serialize
     * @param int $status HTTP status
     * @param array<string, string|list<string>> $headers Additional headers
     * @param int $flags json_encode flags
     * @param int<1, max> $depth json_encode depth
     * @return self Response chosen by negotiation
     *
     * @throws RuntimeException When eager JSON encoding fails
     */
    public static function auto(
        Request $r,
        JsonSerializable|array|string|int|float|bool|null $data,
        int $status = StatusEnum::OK->value,
        array $headers = [],
        int $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        int $depth = 512,
    ): self {
        // Ask the request which of these it prefers (client Accept order wins).
        $want = ContentTypeNegotiator::chooseFromRequest(
            $r,
            [MediaTypeEnum::JSON->base(), '+json', MediaTypeEnum::PLAIN->base()],
        ) ?? MediaTypeEnum::JSON->base();

        // JSON path (recognize vendor/structured +json)
        if (MediaTypeEnum::isJsonLike($want)) {
            $jsonData = is_array($data) ? self::mixedMap($data) : $data;
            $resp = self::json($jsonData, $status, $headers, $flags, $depth);

            // Preserve the negotiated +json type (e.g., application/vnd.api+json)
            if ($want !== MediaTypeEnum::JSON->base()) {
                // JSON:API and many +json types prefer no charset parameter.
                return $resp->withHeader('Content-Type', $want);
            }

            return $resp;
        }

        // Plain text path
        if (is_string($data) || is_scalar($data) || $data === null) {
            return self::plaintext((string) $data, $status, $headers);
        }

        // Complex payload but client prefers text: serialize to JSON string as text/plain
        $payload = $data instanceof JsonSerializable ? $data->jsonSerialize() : $data;
        $json = \json_encode($payload, $flags, $depth);
        if ($json === false) {
            throw new RuntimeException('JSON encode error: ' . \json_last_error_msg());
        }
        $headers = ['Content-Type' => $headers['Content-Type'] ?? MediaTypeEnum::PLAIN->value] + $headers;

        return new self($status, new Stream($json), $headers);
    }

    /**
     * Convenience factory to create a Response from string content.
     *
     * @param string $content Body content
     * @param int $status HTTP status
     * @param array<string, string|list<string>> $headers Additional headers
     * @return self New Response
     */
    public static function create(string $content = '', int $status = StatusEnum::OK->value, array $headers = []): self
    {
        return new self($status, $content, $headers);
    }

    /**
     * Create a download response (alias for attachment with optional name).
     *
     * @param string|Stream $file File path or Stream
     * @param string|null $name Optional filename
     * @param array<string, string|list<string>> $headers Additional headers
     * @param string|null $mime Optional MIME type
     * @return self Download response
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

    /**
     * Create an empty response with Content-Length: 0.
     *
     * @param int $code HTTP status code
     * @param array<string, string|list<string>> $headers Additional headers
     * @return self Response with empty body
     */
    public static function empty(int $code, array $headers = []): self
    {
        $resp = new self($code, new Stream(''), ['Content-Length' => '0']);
        foreach (self::headerMap($headers) as $name => $value) {
            $resp = $resp->withHeader($name, $value);
        }

        return $resp;
    }

    /**
     * Create an inline file response suitable for embedding in-browser.
     *
     * - Sets Content-Disposition: inline and retains Content-Length when known.
     *
     * @param string|Stream $file File path or Stream
     * @param string|null $name Suggested filename
     * @param string|null $mime Optional MIME type
     * @param array<string, string|list<string>> $headers Additional headers
     * @return self Inline response
     */
    public static function inline(
        string|Stream $file,
        ?string $name = null,
        ?string $mime = null,
        array $headers = [],
    ): self {
        $name ??= is_string($file) ? basename($file) : 'inline';
        $stream = $file instanceof Stream ? $file : self::openFileStream($file);
        $mime ??= MediaTypeEnum::fromFilename($name)->value;

        $defaults = [
            'Content-Type' => $mime,
            'Content-Disposition' => ContentDisposition::inline($name),
        ];
        if ($stream->getSize() !== null && !isset($headers['Content-Length'])) {
            $defaults['Content-Length'] = (string) $stream->getSize();
        }

        return new self(StatusEnum::OK->value, $stream, $defaults + $headers);
    }

    /**
     * Create a JSON response.
     *
     * - Encodes $data using json_encode unless a lazy encoder is used (callable/JsonSerializable).
     * - Uses LazyJsonStream for deferred encoding when appropriate.
     * - Ensures Content-Type application/json; charset=utf-8 by default.
     *
     * @param array<string, mixed>|JsonSerializable|string|int|float|bool|null $data Data or lazy JsonSerializable
     * @param int $status HTTP status
     * @param array<string, string|list<string>> $headers Additional headers
     * @param int $flags json_encode flags
     * @param int<1, max> $depth json_encode depth
     * @return self JSON Response
     *
     * @throws RuntimeException When json_encode fails for eager encoding
     */
    public static function json(
        JsonSerializable|array|string|int|float|bool|null $data,
        int $status = StatusEnum::OK->value,
        array $headers = [],
        int $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        int $depth = 512,
    ): self {
        $headers += ['Content-Type' => MediaTypeEnum::JSON->base() . '; charset=utf-8'];

        if ($data instanceof JsonSerializable) {
            $stream = new LazyJsonStream($data, $flags, $depth);

            return new self($status, $stream, $headers);
        }

        $json = \json_encode($data, $flags, $depth);
        if ($json === false) {
            throw new RuntimeException('JSON encode error: ' . \json_last_error_msg());
        }

        return new self($status, new Stream($json), $headers);
    }

    /**
     * Create a 204 No Content response.
     *
     * @param array<string, string|list<string>> $headers Additional headers
     * @return self 204 Response with Content-Length: 0
     */
    public static function noContent(array $headers = []): self
    {
        return self::empty(StatusEnum::NO_CONTENT->value, $headers);
    }

    /* --------------------------------------------------------------
       JSON + Redirect helpers
       -------------------------------------------------------------- */

    /**
     * Create a plaintext response.
     *
     * - Ensures a Content-Type of text/plain; charset=utf-8 unless overridden.
     *
     * @param string $msg Body text
     * @param int $code HTTP status code
     * @param array<string, string|list<string>> $headers Additional headers
     * @return self Plaintext Response
     */
    public static function plaintext(string $msg, int $code = StatusEnum::BAD_REQUEST->value, array $headers = []): self
    {
        $headers = ['Content-Type' => $headers['Content-Type'] ?? MediaTypeEnum::PLAIN->value] + $headers;

        return new self($code, new Stream($msg), $headers);
    }

    /**
     * Serve a local downloadable file with byte-range support.
     *
     * @param Request $req Incoming request
     * @param string $absolutePath Absolute file path
     * @param string|null $name Download filename
     * @param string|null $mime Optional MIME type
     * @param array<string,string> $headers Additional headers
     */
    public static function rangedDownload(
        Request $req,
        string $absolutePath,
        ?string $name = null,
        ?string $mime = null,
        array $headers = [],
    ): self {
        $name ??= \basename($absolutePath);
        $headers += [
            'Content-Disposition' => ContentDisposition::attachment($name),
        ];

        return self::rangedFile($req, $absolutePath, $mime, $headers);
    }

    /**
     * Serve a local file with byte-range support (200/206/416).
     *
     * @param Request $req Incoming request
     * @param string $absolutePath Absolute file path
     * @param string|null $mime Optional MIME type
     * @param array<string,string> $headers Additional headers
     */
    public static function rangedFile(
        Request $req,
        string $absolutePath,
        ?string $mime = null,
        array $headers = [],
    ): self {
        $name = \basename($absolutePath);
        $mediaType = $mime ?? MediaTypeEnum::fromFilename($name)->value;

        return RangeResponder::forFile($req, $absolutePath, $mediaType, $headers);
    }

    /**
     * Create a redirect response.
     *
     * - Validates that $status is a redirect status.
     * - Sets Location and clears entity headers.
     *
     * @param string $uri Target URI
     * @param int $status Redirect status (3xx)
     * @return self Redirect Response
     *
     * @throws \InvalidArgumentException When $status is not a 3xx code
     */
    public static function redirect(string $uri, int $status = StatusEnum::FOUND->value): self
    {
        $s = StatusEnum::tryFrom($status);
        if (!$s || !$s->isRedirect()) {
            throw new \InvalidArgumentException('Redirect status must be a 3xx code.');
        }

        return new self($status, new Stream(''))
            ->withSmartHeader('Location', $uri)
            ->withHeader('Cache-Control', 'no-store')
            ->withoutHeader('Content-Type')
            ->withoutHeader('Content-Length');
    }

    /* --------------------------------------------------------------
       New: streaming helper (no Content-Length, emitter streams live)
       -------------------------------------------------------------- */

    /**
     * Create a live streaming response.
     *
     * - Removes any Content-Length header.
     * - Sets conservative caching and buffering defaults suitable for streams.
     * - $producer may be a callable that yields strings or an iterable of strings.
     *
     * @param callable(): (iterable<string>|string)|iterable<string> $producer Callable returning iterable|string or an iterable of chunks
     * @param int $status HTTP status code
     * @param array<string, string|list<string>> $headers Initial headers
     * @return self Streaming Response instance
     */
    public static function stream(
        callable|iterable $producer,
        int $status = StatusEnum::OK->value,
        array $headers = [],
    ): self {
        unset($headers['Content-Length'], $headers['content-length']);

        $headers = [
            'Cache-Control' => $headers['Cache-Control'] ?? 'no-store',
            'X-Accel-Buffering' => $headers['X-Accel-Buffering'] ?? 'no',
        ] + $headers;

        $resp = new self($status, new Stream(''), $headers);
        $resp->producer = self::normalizeProducer($producer);

        return $resp;
    }

    /**
     * Create a streaming download response.
     *
     * - If $file is a filename it opens a stream; otherwise uses provided Stream.
     * - Sets Content-Type and Content-Disposition and Content-Length when known.
     *
     * @param string|Stream $file Filename or Stream
     * @param string|null $name Suggested filename
     * @param string $mime MIME type
     * @param array<string, string|list<string>> $headers Additional headers
     * @return self Streaming download response
     */
    public static function streamDownload(
        string|Stream $file,
        ?string $name = null,
        string $mime = MediaTypeEnum::OCTET->value,
        array $headers = [],
    ): self {
        if (\is_string($file)) {
            $stream = self::openFileStream($file);
            $len = filesize($file) ?: null;
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
            $headers['Content-Length'] = (string) $len;
        }

        return new self(StatusEnum::OK->value, $stream, $headers);
    }

    /**
     * Render a view using the configured view factory and return an HTML response.
     *
     * @param string $view Template name
     * @param array<string,mixed> $data Template payload
     * @param int $status HTTP status code
     * @param array<string,string> $headers Additional headers
     * @param string|null $charset Charset for Content-Type
     * @param string $factoryId Container key for the view factory binding
     */
    public static function view(
        string $view,
        array $data = [],
        int $status = StatusEnum::OK->value,
        array $headers = [],
        ?string $charset = 'utf-8',
        string $factoryId = ViewFactoryInterface::class,
    ): self {
        $container = Container::instance('intermix');
        if (!$container->has($factoryId)) {
            throw new RuntimeException("No view factory bound for {$factoryId}");
        }

        $factory = $container->get($factoryId);
        if (!$factory instanceof ViewFactoryInterface) {
            throw new RuntimeException("View factory '{$factoryId}' must implement " . ViewFactoryInterface::class);
        }

        $html = $factory->render($view, $data);

        if ($charset !== null && $charset !== '') {
            $headers += ['Content-Type' => MediaTypeEnum::HTML->base() . "; charset={$charset}"];
        } else {
            $headers += ['Content-Type' => MediaTypeEnum::HTML->base()];
        }

        return new self($status, new Stream($html), $headers);
    }

    /**
     * Access the Cache-Control builder for this response.
     *
     * @return CacheControl CacheControl instance based on current headers
     */
    public function cache(): CacheControl
    {
        return CacheControl::fromHeaderBag($this->headers);
    }

    /**
     * Get the response body stream.
     *
     * @return BodyStream Body stream instance
     */
    public function getBody(): BodyStream
    {
        return $this->body;
    }

    /**
     * Retrieve a header's values as an array.
     *
     * @param string $n Header name
     * @return list<string> Header values
     */
    public function getHeader(string $n): array
    {
        return $this->headers->get($n);
    }

    /**
     * Retrieve a header's values as a single comma-separated line.
     *
     * @param string $n Header name
     * @return string Header line
     */
    public function getHeaderLine(string $n): string
    {
        return $this->headers->getHeaderLine($n);
    }

    /**
     * Return all response headers as an associative array.
     *
     * @return array<string, list<string>> Header map
     */
    public function getHeaders(): array
    {
        return $this->headers->all();
    }

    /**
     * Get the attached producer closure.
     *
     * @return null|\Closure(): (iterable<string>|string) The normalized producer or null
     */
    public function getProducer(): ?\Closure
    {
        return $this->producer;
    }

    /* --------------------------------------------------------------
       PSR-7-ish surface (withBody clears producer)
       -------------------------------------------------------------- */

    /**
     * Get the HTTP protocol version.
     *
     * @return string Protocol version
     */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * Get the reason phrase for the current status.
     *
     * @return string Reason phrase or empty string
     */
    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase ?? '';
    }

    /**
     * Get the current HTTP status code.
     *
     * @return int Status code
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Determine whether the response has a header.
     *
     * @param string $n Header name
     * @return bool True when present
     */
    public function hasHeader(string $n): bool
    {
        return $this->headers->has($n);
    }

    /**
     * Whether this response is a live streaming response.
     *
     * @return bool True when a producer is attached
     */
    public function isStreaming(): bool
    {
        return $this->producer !== null;
    }

    /**
     * Return a copy with an additional header value appended.
     *
     * @param string $n Header name
     * @param string|array<int, string> $v Header value(s)
     * @return self New Response instance
     */
    public function withAddedHeader(string $n, string|array $v): self
    {
        return $this->copy(headers: $this->headers->withAdded($n, $v));
    }

    /**
     * Return a copy with a new body stream. Clears any streaming producer.
     *
     * @param BodyStream $b New body stream
     * @return self New Response instance
     */
    public function withBody(BodyStream $b): self
    {
        $x = $this->copy(body: $b);
        $x->producer = null;

        return $x;
    }

    /**
     * Apply a CacheControl editing closure and return a new Response with the result.
     *
     * @param \Closure $edit Closure receiving a CacheControl instance and returning an edited one
     * @return self New Response with updated Cache-Control header
     */
    public function withCache(\Closure $edit): self
    {
        $cc = $edit($this->cache());
        if (!$cc instanceof CacheControl) {
            throw new RuntimeException('withCache() closure must return CacheControl.');
        }

        return $this->withHeader('Cache-Control', (string) $cc);
    }

    /**
     * Return a copy with the specified header replaced.
     *
     * @param string $n Header name
     * @param string|array<int, string> $v Header value(s)
     * @return self New Response instance
     */
    public function withHeader(string $n, string|array $v): self
    {
        return $this->copy(headers: $this->headers->with($n, $v));
    }

    /**
     * Return a copy without the given header.
     *
     * @param string $n Header name
     * @return self New Response instance
     */
    public function withoutHeader(string $n): self
    {
        return $this->copy(headers: $this->headers->without($n));
    }

    /**
     * Return a copy with the provided protocol version.
     *
     * @param string $v Protocol version
     * @return self New Response instance
     */
    public function withProtocolVersion(string $v): self
    {
        return $this->copy(protocolVersion: $v);
    }

    /**
     * Set a header using "smart" merging semantics and return a copy.
     *
     * @param string $name Header name
     * @param string $value Header value
     * @return self New Response with header set
     */
    public function withSmartHeader(string $name, string $value): self
    {
        return $this->copy(headers: $this->headers->withSmart($name, $value));
    }

    /**
     * Return a copy with a changed status code and optional reason phrase.
     *
     * - Validates the status value is within 100..599.
     *
     * @param int $code New status code
     * @param string $reasonPhrase Optional reason phrase
     * @return self New Response instance
     *
     * @throws RuntimeException When the status code is out of range
     */
    public function withStatus(int $code, string $reasonPhrase = ''): self
    {
        if ($code < 100 || $code > 599) {
            throw new RuntimeException("Invalid HTTP status: {$code}");
        }

        return $this->copy(
            statusCode: $code,
            reasonPhrase: $reasonPhrase !== '' ? $reasonPhrase : self::statusText($code),
        );
    }

    /* ───────────────── private helpers ───────────────── */

    /**
     * Base headers used for downloads.
     *
     * @param string $name Filename
     * @param string $mime MIME type
     * @return array<string, string> Base header map
     */
    private static function baseDownloadHeaders(string $name, string $mime): array
    {
        return [
            'Content-Type' => $mime,
            'Content-Disposition' => ContentDisposition::attachment($name),
        ];
    }

    /**
     * Choose a Content-Length string if available.
     *
     * @param string|Stream $file File path or Stream
     * @param Stream $stream Stream instance
     * @param int|null $fsSize Filesystem size when known
     * @return string|null Content-Length string or null
     */
    private static function chooseLength(string|Stream $file, Stream $stream, ?int $fsSize): ?string
    {
        $len = is_string($file) ? $fsSize : ($stream->getSize() ?? null);

        return $len !== null ? (string) $len : null;
    }

    /**
     * Produce a small ETag token from available metadata.
     *
     * @param int|null $size File size or null
     * @param int|null $mtime File mtime or null
     * @param string $name Filename used in seed
     * @return string|null Quoted ETag or null when no metadata available
     */
    private static function etagFromMeta(?int $size, ?int $mtime, string $name): ?string
    {
        if ($size === null && $mtime === null) {
            return null;
        }
        $seed = ($size ?? -1) . '|' . ($mtime ?? -1) . '|' . $name;

        return Utils::generateEtag($seed);
    }

    /**
     * Format an mtime as an HTTP date string or return null.
     *
     * @param int|null $mtime UNIX epoch or null
     * @return string|null RFC-7231 date string or null
     */
    private static function formatHttpDate(?int $mtime): ?string
    {
        return $mtime ? gmdate('D, d M Y H:i:s', $mtime) . ' GMT' : null;
    }

    /**
     * @param array<mixed> $headers
     * @return array<string, string|list<string>>
     */
    private static function headerMap(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }

            if (is_string($value)) {
                $out[$name] = $value;

                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            $vals = [];
            foreach ($value as $item) {
                if (!is_string($item)) {
                    continue;
                }
                $vals[] = $item;
            }

            $out[$name] = $vals;
        }

        return $out;
    }

    /**
     * Infer a MIME type for a filename, falling back to explicit value.
     *
     * @param string $name Filename
     * @param string|null $explicit Explicit MIME if provided
     * @return string Resolved MIME type
     */
    private static function inferMime(string $name, ?string $explicit): string
    {
        return $explicit ?? MediaTypeEnum::fromFilename($name)->value;
    }

    /**
     * Retrieve filesystem metadata for a file input when available.
     *
     * @param string|Stream $file File path or Stream
     * @return array{0:?int,1:?int} [size|null, mtime|null]
     */
    private static function metaFor(string|Stream $file): array
    {
        if (!is_string($file)) {
            return [null, null];
        }
        $size = filesize($file) ?: null;
        $mtime = filemtime($file) ?: null;

        return [$size, $mtime];
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function mixedMap(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }
            $out[$key] = $item;
        }

        return $out;
    }

    /* -------------------------------------------------------------- */

    /**
     * Attempt to obtain an mtime from a Stream's metadata URI when available.
     *
     * @param Stream $stream Stream instance
     * @return int|null File mtime or null when not applicable
     */
    private static function mtimeFromStream(Stream $stream): ?int
    {
        $uri = $stream->getMetadata('uri');
        if (is_string($uri) && $uri !== '' && is_file($uri)) {
            return filemtime($uri) ?: null;
        }

        return null;
    }

    /**
     * Normalize a producer value into a closure that consistently returns
     * either an iterable of strings or a single string.
     *
     * @param callable(): (iterable<string>|string)|iterable<string> $producer Callable or iterable to normalize
     * @return \Closure Normalized producer closure
     */
    private static function normalizeProducer(callable|iterable $producer): \Closure
    {
        if (is_iterable($producer)) {
            return static fn() => $producer;
        }

        return static function () use ($producer) {
            $out = $producer();
            if ($out instanceof \Generator || is_iterable($out)) {
                return $out;
            }

            return [$out];
        };
    }

    /**
     * Open a file as a Stream, throwing on failure.
     *
     * @param string $file Path to open
     * @return Stream Stream wrapping the opened resource
     *
     * @throws RuntimeException When the file cannot be opened
     */
    private static function openFileStream(string $file): Stream
    {
        $h = fopen($file, 'rb');
        if ($h === false) {
            throw new RuntimeException("Unable to open file for download: {$file}");
        }

        return new Stream($h);
    }

    /**
     * Helper to set a default header value only when caller did not supply it.
     *
     * @param array<string, string> &$target Default header map to mutate
     * @param string $name Header name
     * @param string|null $value Value to set when present
     * @param array<string, string|list<string>> $caller Original caller headers to check
     */
    private static function putIfAbsent(array &$target, string $name, ?string $value, array $caller): void
    {
        if ($value !== null && !array_key_exists($name, $caller)) {
            $target[$name] = $value;
        }
    }

    /**
     * Map a numeric status code to its standard reason text.
     *
     * @param int $code Status code
     * @return string Reason text or empty string
     */
    private static function statusText(int $code): string
    {
        return StatusEnum::text($code);
    }

    /* --------------------------------------------------------------
       Low-complexity helpers for attachment()
       -------------------------------------------------------------- */

    /**
     * Ensure a Stream instance for a file input.
     *
     * @param string|Stream $file File path or existing Stream
     * @return Stream Stream instance wrapping the file/resource
     */
    private static function streamFor(string|Stream $file): Stream
    {
        return $file instanceof Stream ? $file : self::openFileStream($file);
    }

    /**
     * Internal clone-and-replace helper used by immutable setters.
     *
     * @param int|null $statusCode Optional replacement status
     * @param HeaderBag|null $headers Optional replacement HeaderBag
     * @param BodyStream|null $body Optional replacement BodyStream
     * @param string|null $protocolVersion Optional replacement protocolVersion
     * @param string|null $reasonPhrase Optional replacement reasonPhrase
     * @return self Cloned and modified Response instance
     */
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
}
