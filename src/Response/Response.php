<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response;

use Infocyph\InterMix\Remix\MacroMix;
use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Core\StringBody;
use Infocyph\Webrick\Request\Http\ContentNegotiator;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\HeaderBag;
use Infocyph\Webrick\Response\Body\FileBody;
use Infocyph\Webrick\Response\Headers\CacheControl;
use Infocyph\Webrick\Response\Headers\ContentDisposition;
use Infocyph\Webrick\Response\Internal\Utils;
use Infocyph\Webrick\Response\Range\RangeResponder;
use JsonSerializable;
use RuntimeException;

/** Immutable HTTP response optimized for native string and file bodies. */
class Response
{
    use MacroMix;

    private const string INVALID_REASON_PHRASE = '/[\x00-\x08\x0A-\x1F\x7F]/';

    private const string PROTOCOL_VERSION_RX = '/^[0-9]+(?:\.[0-9]+)?$/D';

    private BodyStream|string $body;

    private ?BodyStream $bodyFacade = null;

    private HeaderBag $headers;

    /** @var null|\Closure():iterable<string> */
    private ?\Closure $producer = null;

    /** @param array<string,string|list<string>> $headers */
    public function __construct(
        private int $statusCode = StatusEnum::OK->value,
        BodyStream|string|null $body = null,
        array $headers = [],
        private string $protocolVersion = '1.1',
        private ?string $reasonPhrase = null,
    ) {
        self::assertStatus($this->statusCode);
        self::assertProtocolVersion($this->protocolVersion);
        self::assertReasonPhrase($this->reasonPhrase);
        $this->headers = new HeaderBag($headers);
        $this->body = $body ?? '';
    }

    /** @param array<string,string|list<string>> $headers */
    public static function attachment(
        string|Stream $file,
        string $name,
        ?string $mime = null,
        array $headers = [],
    ): self {
        $body = is_string($file) ? new FileBody($file) : $file;
        [$size, $mtime] = self::metaFor($file);
        $size ??= $body->getSize();
        $mtime ??= $body instanceof Stream ? self::mtimeFromStream($body) : null;
        $mime = self::inferMime($name, $mime);
        $bag = self::withDefaults(new HeaderBag($headers), self::baseDownloadHeaders($name, $mime));

        if ($size !== null && !$bag->has('Content-Length')) {
            $bag = $bag->with('Content-Length', (string) $size);
        }
        $lastModified = self::formatHttpDate($mtime);
        if ($lastModified !== null && !$bag->has('Last-Modified')) {
            $bag = $bag->with('Last-Modified', $lastModified);
        }
        $etag = self::weakEtagFromMeta($size, $mtime, $name);
        if ($etag !== null && !$bag->has('ETag')) {
            $bag = $bag->with('ETag', $etag);
        }

        return new self(StatusEnum::OK->value, $body, $bag->all());
    }

    /**
     * @param array<array-key,mixed>|JsonSerializable|string|int|float|bool|null $data
     * @param array<string,string|list<string>> $headers
     * @param positive-int $depth
     */
    public static function auto(
        Request $r,
        JsonSerializable|array|string|int|float|bool|null $data,
        int $status = StatusEnum::OK->value,
        array $headers = [],
        int $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        int $depth = 512,
    ): self {
        $want = new ContentNegotiator($r->headers())->preferred([
            MediaTypeEnum::JSON->base(),
            '+json',
            MediaTypeEnum::PLAIN->base(),
        ]);
        if ($want === null) {
            return self::plaintext('Not Acceptable', StatusEnum::NOT_ACCEPTABLE->value, $headers)
                ->withSmartHeader('Vary', 'Accept');
        }

        if (MediaTypeEnum::isJsonLike($want)) {
            $response = self::json($data, $status, $headers, $flags, $depth);

            return $want === MediaTypeEnum::JSON->base()
                ? $response
                : $response->withHeader('Content-Type', $want);
        }

        if (is_string($data) || is_scalar($data) || $data === null) {
            return self::plaintext((string) $data, $status, $headers);
        }

        $payload = $data instanceof JsonSerializable ? $data->jsonSerialize() : $data;
        $json = json_encode($payload, $flags, $depth);
        if ($json === false) {
            throw new RuntimeException('JSON encode error: ' . json_last_error_msg());
        }
        $bag = self::withDefaults(new HeaderBag($headers), ['Content-Type' => MediaTypeEnum::PLAIN->value]);

        return new self($status, $json, $bag->all());
    }

    /** @param array<string,string|list<string>> $headers */
    public static function create(string $content = '', int $status = StatusEnum::OK->value, array $headers = []): self
    {
        return new self($status, $content, $headers);
    }

    /** @param array<string,string|list<string>> $headers */
    public static function download(
        string|Stream $file,
        ?string $name = null,
        array $headers = [],
        ?string $mime = null,
    ): self {
        $name ??= is_string($file) ? basename($file) : 'download';

        return self::attachment($file, $name, $mime, $headers);
    }

    /** @param array<string,string|list<string>> $headers */
    public static function empty(int $code, array $headers = []): self
    {
        $bag = new HeaderBag($headers);
        if (($code >= 100 && $code < 200) || $code === StatusEnum::NO_CONTENT->value) {
            $bag = $bag->without('Content-Length');
        } elseif ($code === StatusEnum::RESET_CONTENT->value) {
            $bag = $bag->with('Content-Length', '0');
        } elseif ($code !== StatusEnum::NOT_MODIFIED->value && !$bag->has('Content-Length')) {
            $bag = $bag->with('Content-Length', '0');
        }

        return new self($code, '', $bag->all());
    }

    /** @param array<string,string|list<string>> $headers */
    public static function inline(
        string|Stream $file,
        ?string $name = null,
        ?string $mime = null,
        array $headers = [],
    ): self {
        $name ??= is_string($file) ? basename($file) : 'inline';
        $body = is_string($file) ? new FileBody($file) : $file;
        $mime ??= MediaTypeEnum::fromFilename($name)->value;
        $bag = self::withDefaults(new HeaderBag($headers), [
            'Content-Type' => $mime,
            'Content-Disposition' => ContentDisposition::inline($name),
        ]);
        $size = $body->getSize();
        if ($size !== null && !$bag->has('Content-Length')) {
            $bag = $bag->with('Content-Length', (string) $size);
        }

        return new self(StatusEnum::OK->value, $body, $bag->all());
    }

    /**
     * @param array<array-key,mixed>|JsonSerializable|string|int|float|bool|null $data
     * @param array<string,string|list<string>> $headers
     * @param positive-int $depth
     */
    public static function json(
        JsonSerializable|array|string|int|float|bool|null $data,
        int $status = StatusEnum::OK->value,
        array $headers = [],
        int $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        int $depth = 512,
    ): self {
        $bag = self::withDefaults(
            new HeaderBag($headers),
            ['Content-Type' => MediaTypeEnum::JSON->base() . '; charset=utf-8'],
        );
        $payload = $data instanceof JsonSerializable ? $data->jsonSerialize() : $data;
        $json = json_encode($payload, $flags, $depth);
        if ($json === false) {
            throw new RuntimeException('JSON encode error: ' . json_last_error_msg());
        }

        return new self($status, $json, $bag->all());
    }

    /** @param array<string,string|list<string>> $headers */
    public static function noContent(array $headers = []): self
    {
        return self::empty(StatusEnum::NO_CONTENT->value, $headers);
    }

    /** @param array<string,string|list<string>> $headers */
    public static function plaintext(string $msg, int $code = StatusEnum::BAD_REQUEST->value, array $headers = []): self
    {
        $bag = self::withDefaults(new HeaderBag($headers), ['Content-Type' => MediaTypeEnum::PLAIN->value]);

        return new self($code, $msg, $bag->all());
    }

    /** @param array<string,string> $headers */
    public static function rangedDownload(
        Request $req,
        string $absolutePath,
        ?string $name = null,
        ?string $mime = null,
        array $headers = [],
    ): self {
        $name ??= basename($absolutePath);
        $bag = self::withDefaults(
            new HeaderBag($headers),
            ['Content-Disposition' => ContentDisposition::attachment($name)],
        );

        return self::rangedFile($req, $absolutePath, $mime, self::singleValueHeaders($bag));
    }

    /** @param array<string,string> $headers */
    public static function rangedFile(
        Request $req,
        string $absolutePath,
        ?string $mime = null,
        array $headers = [],
    ): self {
        $name = basename($absolutePath);
        $mediaType = $mime ?? MediaTypeEnum::fromFilename($name)->value;

        return RangeResponder::forFile($req, $absolutePath, $mediaType, $headers);
    }

    public static function redirect(string $uri, int $status = StatusEnum::FOUND->value): self
    {
        $resolved = StatusEnum::tryFrom($status);
        if (!$resolved instanceof StatusEnum || !$resolved->isRedirect()) {
            throw new \InvalidArgumentException('Redirect status must be one of 300, 301, 302, 303, 305, 307, or 308.');
        }

        return new self($status, '')
            ->withSmartHeader('Location', $uri)
            ->withHeader('Cache-Control', 'no-store')
            ->withoutHeader('Content-Type')
            ->withoutHeader('Content-Length');
    }

    /**
     * @param callable(): (iterable<string>|string)|iterable<string> $producer
     * @param array<string,string|list<string>> $headers
     */
    public static function stream(
        callable|iterable $producer,
        int $status = StatusEnum::OK->value,
        array $headers = [],
    ): self {
        $bag = (new HeaderBag($headers))->without('Content-Length');
        $bag = self::withDefaults($bag, [
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ]);

        $response = new self($status, '', $bag->all());
        $response->producer = self::normalizeProducer($producer);

        return $response;
    }

    /** @param array<string,string|list<string>> $headers */
    public static function streamDownload(
        string|Stream $file,
        ?string $name = null,
        string $mime = MediaTypeEnum::OCTET->value,
        array $headers = [],
    ): self {
        if (is_string($file)) {
            $body = new FileBody($file);
            $length = $body->getSize();
            $name ??= basename($file);
        } else {
            $body = $file;
            $length = $body->getSize();
            $name ??= 'download';
        }

        $bag = self::withDefaults(new HeaderBag($headers), [
            'Content-Type' => $mime,
            'Content-Disposition' => ContentDisposition::attachment($name),
        ]);
        if ($length !== null) {
            $bag = $bag->with('Content-Length', (string) $length);
        }

        return new self(StatusEnum::OK->value, $body, $bag->all());
    }

    public function cache(): CacheControl
    {
        return CacheControl::fromHeaderBag($this->headers);
    }

    public function getBody(): BodyStream
    {
        if ($this->body instanceof BodyStream) {
            return $this->body;
        }

        return $this->bodyFacade ??= new StringBody($this->body);
    }

    public function getBodySize(): ?int
    {
        return is_string($this->body) ? strlen($this->body) : $this->body->getSize();
    }

    public function getFileBody(): ?FileBody
    {
        return $this->body instanceof FileBody ? $this->body : null;
    }

    /** @return list<string> */
    public function getHeader(string $name): array
    {
        return $this->headers->get($name);
    }

    public function getHeaderLine(string $name): string
    {
        return $this->headers->getHeaderLine($name);
    }

    /** @return array<string,list<string>> */
    public function getHeaders(): array
    {
        return $this->headers->all();
    }

    /** @return null|\Closure():iterable<string> */
    public function getProducer(): ?\Closure
    {
        return $this->producer;
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase ?? self::statusText($this->statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getStringBody(): ?string
    {
        return is_string($this->body) ? $this->body : null;
    }

    public function hasHeader(string $name): bool
    {
        return $this->headers->has($name);
    }

    public function isStreaming(): bool
    {
        return $this->producer !== null;
    }

    public function isStringBody(): bool
    {
        return is_string($this->body);
    }

    /** @param string|list<string> $value */
    public function withAddedHeader(string $name, string|array $value): self
    {
        return $this->copy(headers: $this->headers->withAdded($name, $value));
    }

    public function withBody(BodyStream|string $body): self
    {
        $response = $this->copy(body: $body);
        $response->producer = null;

        return $response;
    }

    public function withCache(\Closure $edit): self
    {
        $cache = $edit($this->cache());
        if (!$cache instanceof CacheControl) {
            throw new RuntimeException('withCache() closure must return CacheControl.');
        }

        return $this->withHeader('Cache-Control', (string) $cache);
    }

    /** @param string|list<string> $value */
    public function withHeader(string $name, string|array $value): self
    {
        return $this->copy(headers: $this->headers->with($name, $value));
    }

    public function withoutHeader(string $name): self
    {
        return $this->copy(headers: $this->headers->without($name));
    }

    public function withProtocolVersion(string $version): self
    {
        self::assertProtocolVersion($version);

        return $this->copy(protocolVersion: $version);
    }

    public function withSmartHeader(string $name, string $value): self
    {
        return $this->copy(headers: $this->headers->withSmart($name, $value));
    }

    public function withStatus(int $code, string $reasonPhrase = ''): self
    {
        self::assertStatus($code);
        self::assertReasonPhrase($reasonPhrase);

        return $this->copy(
            statusCode: $code,
            reasonPhrase: $reasonPhrase !== '' ? $reasonPhrase : self::statusText($code),
        );
    }

    private static function assertProtocolVersion(string $version): void
    {
        if (preg_match(self::PROTOCOL_VERSION_RX, $version) !== 1) {
            throw new \InvalidArgumentException('Invalid HTTP protocol version.');
        }
    }

    private static function assertReasonPhrase(?string $reasonPhrase): void
    {
        if ($reasonPhrase !== null && preg_match(self::INVALID_REASON_PHRASE, $reasonPhrase) === 1) {
            throw new \InvalidArgumentException('HTTP reason phrase must not contain control characters.');
        }
    }

    private static function assertStatus(int $code): void
    {
        if ($code < 100 || $code > 599) {
            throw new RuntimeException("Invalid HTTP status: {$code}");
        }
    }

    /** @return array<string,string> */
    private static function baseDownloadHeaders(string $name, string $mime): array
    {
        return [
            'Content-Type' => $mime,
            'Content-Disposition' => ContentDisposition::attachment($name),
        ];
    }

    private static function formatHttpDate(?int $mtime): ?string
    {
        return $mtime !== null ? gmdate('D, d M Y H:i:s', $mtime) . ' GMT' : null;
    }

    private static function inferMime(string $name, ?string $explicit): string
    {
        return $explicit ?? MediaTypeEnum::fromFilename($name)->value;
    }

    /** @return array{0:?int,1:?int} */
    private static function metaFor(string|Stream $file): array
    {
        if (!is_string($file)) {
            return [null, null];
        }

        $size = filesize($file);
        $mtime = filemtime($file);

        return [$size === false ? null : $size, $mtime === false ? null : $mtime];
    }

    private static function mtimeFromStream(Stream $stream): ?int
    {
        $uri = $stream->getMetadata('uri');
        if (!is_string($uri) || $uri === '' || !is_file($uri)) {
            return null;
        }
        $mtime = filemtime($uri);

        return $mtime === false ? null : $mtime;
    }

    /**
     * @param callable(): (iterable<string>|string)|iterable<string> $producer
     * @return \Closure():iterable<string>
     */
    private static function normalizeProducer(callable|iterable $producer): \Closure
    {
        if (is_iterable($producer)) {
            return static function () use ($producer): iterable {
                yield from $producer;
            };
        }

        return static function () use ($producer): iterable {
            $output = $producer();

            return is_iterable($output) ? $output : [(string) $output];
        };
    }

    /** @return array<string,string> */
    private static function singleValueHeaders(HeaderBag $bag): array
    {
        $headers = [];
        foreach ($bag->all() as $name => $values) {
            if ($values !== []) {
                $headers[$name] = $values[count($values) - 1];
            }
        }

        return $headers;
    }

    /** @param array<string,string> $defaults */
    private static function withDefaults(HeaderBag $bag, array $defaults): HeaderBag
    {
        foreach ($defaults as $name => $value) {
            if (!$bag->has($name)) {
                $bag = $bag->with($name, $value);
            }
        }

        return $bag;
    }

    private static function statusText(int $code): string
    {
        return StatusEnum::text($code);
    }

    private static function weakEtagFromMeta(?int $size, ?int $mtime, string $name): ?string
    {
        if ($size === null && $mtime === null) {
            return null;
        }

        return 'W/' . Utils::generateEtag(($size ?? -1) . '|' . ($mtime ?? -1) . '|' . $name);
    }

    private function copy(
        ?int $statusCode = null,
        ?HeaderBag $headers = null,
        BodyStream|string|null $body = null,
        ?string $protocolVersion = null,
        ?string $reasonPhrase = null,
    ): self {
        $response = clone $this;
        $response->statusCode = $statusCode ?? $this->statusCode;
        $response->headers = $headers ?? $this->headers;
        $response->body = $body ?? $this->body;
        $response->bodyFacade = $body === null ? $this->bodyFacade : null;
        $response->protocolVersion = $protocolVersion ?? $this->protocolVersion;
        $response->reasonPhrase = $reasonPhrase ?? $this->reasonPhrase;

        return $response;
    }
}
