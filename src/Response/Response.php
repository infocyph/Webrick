<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response;

use Infocyph\Webrick\Response\Internal\HeaderBag;
use Infocyph\InterMix\Remix\MacroMix;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Immutable PSR-7 Response + MacroMix.
 *
 * Examples
 * --------
 * ResponseMacros::boot();
 * return Response::json(['ok'=>true])->withStatus(201);
 */
class Response implements ResponseInterface
{
    use MacroMix;

    private readonly HeaderBag     $headers;
    private readonly StreamInterface $body;

    public function __construct(
        private int    $statusCode      = 200,
        StreamInterface|string|null $body = null,
        array          $headers         = [],
        private string $protocolVersion = '1.1',
        private ?string $reasonPhrase   = null,
    ) {
        $this->headers = new HeaderBag($headers);
        $this->body    = $body instanceof StreamInterface ? $body
            : new Stream($body ?? '');
        $this->reasonPhrase ??= self::statusText($this->statusCode);
    }

    /* ------------------------------------------------- MessageInterface */

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion($version): self
    {
        return $this->clone(protocolVersion: (string) $version);
    }

    public function getHeaders(): array
    {
        return $this->headers->all();
    }

    public function hasHeader($name): bool
    {
        return $this->headers->has($name);
    }

    public function getHeader($name): array
    {
        return $this->headers->get($name);
    }

    public function getHeaderLine($name): string
    {
        return $this->headers->line($name);
    }

    public function withHeader($name, $value): self
    {
        return $this->clone(headers: $this->headers->with($name, $value));
    }

    public function withAddedHeader($name, $value): self
    {
        return $this->clone(headers: $this->headers->withAdded($name, $value));
    }

    public function withoutHeader($name): self
    {
        return $this->clone(headers: $this->headers->without($name));
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): self
    {
        return $this->clone(body: $body);
    }

    public static function empty(int $code, array $headers = []): self
    {
        $resp = new self($code);
        foreach ($headers as $n => $v) {
            $resp = $resp->withHeader($n, $v);
        }
        return $resp;
    }

    /* ------------------------------------------------ ResponseInterface */

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
        $code = (int) $code;
        if ($code < 100 || $code > 599) {
            throw new \InvalidArgumentException("Invalid HTTP status: {$code}");
        }
        return $this->clone(
            statusCode    : $code,
            reasonPhrase  : $reasonPhrase ?: self::statusText($code),
        );
    }

    /* ----------------------------------------------------- internals  */

    private static function statusText(int $code): string
    {
        return self::$map[$code] ?? '';
    }

    /** Fast clone-with helper (named-args for clarity). */
    private function clone(
        ?int             $statusCode      = null,
        ?HeaderBag       $headers         = null,
        ?StreamInterface $body            = null,
        ?string          $protocolVersion = null,
        ?string          $reasonPhrase    = null,
    ): self {
        $x                     = clone $this;
        $x->statusCode         = $statusCode      ?? $this->statusCode;
        $x->reasonPhrase       = $reasonPhrase    ?? $this->reasonPhrase;
        $x->protocolVersion    = $protocolVersion ?? $this->protocolVersion;
        $x->headers            = $headers         ?? clone $this->headers;
        $x->body               = $body            ?? $this->body;
        return $x;
    }

    /* HTTP/1.1 reason-phrases (short list sufficient for APIs) */
    private static array $map = [
        // 2xx
        200 => 'OK',                    201 => 'Created',
        202 => 'Accepted',              204 => 'No Content',
        // 3xx
        301 => 'Moved Permanently',     302 => 'Found',
        304 => 'Not Modified',
        // 4xx
        400 => 'Bad Request',           401 => 'Unauthorized',
        403 => 'Forbidden',             404 => 'Not Found',
        405 => 'Method Not Allowed',    412 => 'Precondition Failed',
        416 => 'Range Not Satisfiable', 429 => 'Too Many Requests',
        // 5xx
        500 => 'Internal Server Error', 501 => 'Not Implemented',
        503 => 'Service Unavailable',
    ];
}
