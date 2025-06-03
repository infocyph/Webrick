<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Http;

use Infocyph\ArrayKit\Collection\Collection;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

/**
 * PSR-7 ServerRequest with Webrick helpers:
 *  • createFromGlobals()
 *  • method-override / AJAX / JSON helpers
 *  • magic __get() (EGPCS resolution order)
 *  • RequestHeaders integration
 */
class ServerRequest implements ServerRequestInterface
{
    /* -----------------------------------------------------------------
       Static factory
       ----------------------------------------------------------------- */

    public static function createFromGlobals(): static
    {
        $srv   = $_SERVER;
        $uri   = Uri::fromServerParams($srv);
        $meth  = $srv['REQUEST_METHOD'] ?? 'GET';
        $body  = new Stream(fopen('php://input', 'rb'));
        $proto = str_starts_with(($srv['SERVER_PROTOCOL'] ?? ''), 'HTTP/')
            ? substr($srv['SERVER_PROTOCOL'], 5)
            : '1.1';

        /* 1. bootstrap with empty headers; we'll inject later */
        $req = new static(
            $meth,
            $uri,
            $srv,
            [],              // headers
            $body,
            $proto,
            $_POST,          // parsedBody for normal POST
            $_FILES
        );

        /* 2. gather env headers through RequestHeaders */
        $hdrBag = new RequestHeaders($req)->all()->toArray();
        foreach ($hdrBag as $name => $val) {
            $req = $req->withHeader($name, is_array($val) ? $val : [(string)$val]);
        }

        /* 3. parse urlencoded body for PUT/PATCH/DELETE */
        if (
            \in_array($req->method, ['PUT', 'PATCH', 'DELETE'], true) &&
            str_contains(strtolower($req->getHeaderLine('Content-Type')), 'application/x-www-form-urlencoded')
        ) {
            parse_str((string)$body, $form);
            $req = $req->withParsedBody($form);
        }

        /* 4. populate query & cookie params */
        parse_str($uri->getQuery(), $q);
        $req = $req
            ->withQueryParams($q)
            ->withCookieParams($_COOKIE);

        return $req;
    }

    /* -----------------------------------------------------------------
       Internal state
       ----------------------------------------------------------------- */

    protected string       $method;
    protected UriInterface $uri;
    protected array        $cookieParams = [];
    protected array        $queryParams  = [];
    protected array        $attributes   = [];
    protected array        $headers      = [];

    /* convenience caches */
    private ?Collection $queryCol  = null;
    private ?Collection $postCol   = null;
    private ?Collection $cookieCol = null;
    private ?Collection $serverCol = null;
    private ?Collection $jsonCol   = null;
    private ?Collection $filesCol  = null;
    private ?RequestHeaders $hdrHelper = null;
    private ?string $rawBodyCache  = null;
    private ?string $effectiveMethodCache = null;
    private array $varMap = [];
    private bool $checkEnv = false;

    /* recognised HTTP verbs */
    private static array $validMethods = [
        'GET','POST','PUT','DELETE','PATCH','HEAD','OPTIONS','CONNECT','TRACE'
    ];

    /* -----------------------------------------------------------------
       Constructor (unchanged except for Host optimisation)
       ----------------------------------------------------------------- */

    public function __construct(
        string                   $method,
        UriInterface|string      $uri,
        protected array          $serverParams = [],
        array                    $headers = [],
        protected StreamInterface $body = new Stream(''),
        protected string         $protocolVersion = '1.1',
        protected mixed          $parsedBody = null,
        protected array          $uploadedFiles = [],
        protected ?string        $requestTarget = null
    ) {
        $this->method  = strtoupper($method);
        $this->uri     = $uri instanceof UriInterface ? $uri : new Uri($uri);
        $this->headers = $this->normalizeHeaders($headers);

        if (!$this->hasHeader('Host') && $this->uri->getHost() !== '') {
            $h = $this->uri->getHost();
            if ($this->uri->getPort()) {
                $h .= ':' . $this->uri->getPort();
            }
            $this->headers['Host'] = [$h];
        }

        $this->initVariableMap();
    }

    /* -----------------------------------------------------------
 * Boot-strap EGPCS / request_order precedence  (called once)
 * ----------------------------------------------------------- */
    private function initVariableMap(): void
    {
        $order = $this->determineVariableOrder();
        $this->composeVariableMap($order);
    }

    private function determineVariableOrder(): array
    {
        $vars = strtoupper(preg_replace('/[^EGPCS]/', '', ini_get('variables_order') ?: 'EGPCS'));
        $req  = strtoupper(preg_replace('/[^GPC]/',   '', ini_get('request_order')   ?: ''));

        $seq = str_split($vars);

        if ($req !== '') {
            $seq = array_values(array_diff($seq, ['G', 'P', 'C']));
            $anchor = array_search('E', $seq, true);
            $insert = $anchor === false ? 0 : $anchor + 1;
            foreach (array_reverse(str_split($req)) as $ch) {
                array_splice($seq, $insert, 0, $ch);
            }
        }
        return $seq;
    }

    /* -----  Compose once from the chosen sources  ------------------ */
    private function composeVariableMap(array $order): void
    {
        $sources = [
            'G' => $this->queryParams,
            'P' => (\is_array($this->parsedBody) ? $this->parsedBody : []),
            'C' => $this->cookieParams,
            'S' => $this->serverParams,
        ];

        $map = [];
        foreach ($order as $ch) {
            if (isset($sources[$ch])) {
                $map += $sources[$ch];
            }
        }
        $this->varMap   = $map;
        $this->checkEnv = \in_array('E', $order, true);
    }

    /* -----------------------------------------------------------------
       MessageInterface
       ----------------------------------------------------------------- */

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion($version): self
    {
        $clone = clone $this;
        $clone->protocolVersion = $version;
        return $clone;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader($name): bool
    {
        return isset($this->headers[$this->normalizeHeaderName($name)]);
    }

    public function getHeader($name): array
    {
        return $this->headers[$this->normalizeHeaderName($name)] ?? [];
    }

    public function getHeaderLine($name): string
    {
        return implode(',', $this->getHeader($name));
    }

    public function withHeader($name, $value): self
    {
        $norm = $this->normalizeHeaderName($name);
        $val  = is_array($value) ? array_values($value) : [(string)$value];
        if (($this->headers[$norm] ?? null) === $val) {
            return $this;
        }   // no-op
        $clone              = clone $this;
        $clone->headers[$norm] = $val;
        return $clone;
    }

    public function withAddedHeader($name, $value): self
    {
        $norm = $this->normalizeHeaderName($name);
        $val  = is_array($value) ? $value : [(string)$value];
        if (!$this->hasHeader($norm)) {
            return $this->withHeader($norm, $val);
        }
        $clone = clone $this;
        $clone->headers[$norm] = array_merge($this->headers[$norm], $val);
        return $clone;
    }

    public function withoutHeader($name): self
    {
        $clone = clone $this;
        unset($clone->headers[$this->normalizeHeaderName($name)]);
        return $clone;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): self
    {
        $clone = clone $this;
        $clone->body = $body;
        $clone->rawBodyCache = null;
        return $clone;
    }

    /* -----------------------------------------------------------------
       RequestInterface
       ----------------------------------------------------------------- */

    public function getRequestTarget(): string
    {
        if ($this->requestTarget) {
            return $this->requestTarget;
        }
        $t = $this->uri->getPath() ?: '/';
        if ($q = $this->uri->getQuery()) {
            $t .= '?' . $q;
        }
        return $t;
    }

    public function withRequestTarget($rt): self
    {
        if (preg_match('#\s#', $rt)) {
            throw new InvalidArgumentException('Whitespace in requestTarget');
        }
        $c = clone $this;
        $c->requestTarget = $rt;
        return $c;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod($m): self
    {
        $c = clone $this;
        $c->method = strtoupper($m);
        $c->effectiveMethodCache = null;
        return $c;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, $preserveHost = false): self
    {
        $c = clone $this;
        $c->uri = $uri;
        if (!$preserveHost) {
            $c->headers['Host'] = $uri->getHost() ? [$uri->getHost() . ($uri->getPort() ? ':' . $uri->getPort() : '')] : [];
        } elseif ($uri->getHost() && !$c->hasHeader('Host')) {
            $c->headers['Host'] = [$uri->getHost()];
        }
        return $c;
    }

    /* -----------------------------------------------------------------
       ServerRequestInterface – simple setters
       ----------------------------------------------------------------- */

    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    public function withCookieParams(array $cookies): self
    {
        $x = clone $this;
        $x->cookieParams = $cookies;
        $x->cookieCol = null;
        return $x;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function withQueryParams(array $q): self
    {
        $x = clone $this;
        $x->queryParams = $q;
        $x->queryCol = null;
        return $x;
    }

    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    public function withUploadedFiles(array $u): self
    {
        array_walk_recursive($u, fn ($i) => $i instanceof UploadedFileInterface
            ?: throw new InvalidArgumentException('Invalid uploaded file'));
        $x = clone $this;
        $x->uploadedFiles = $u;
        $x->filesCol = null;
        return $x;
    }

    public function getParsedBody(): mixed
    {
        return $this->parsedBody;
    }

    public function withParsedBody($d): self
    {
        /* PSR-7 mandate */
        if ($d !== null && !\is_array($d) && !\is_object($d)) {
            throw new InvalidArgumentException('Parsed body must be array|object|null');
        }
        $x = clone $this;
        $x->parsedBody = $d;
        $x->postCol = $x->jsonCol = null;
        return $x;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute($n, $def = null): mixed
    {
        return $this->attributes[$n] ?? $def;
    }

    public function withAttribute($n, $v): self
    {
        $x = clone $this;
        $x->attributes[$n] = $v;
        return $x;
    }

    public function withoutAttribute($n): self
    {
        $x = clone $this;
        unset($x->attributes[$n]);
        return $x;
    }

    /* -----------------------------------------------------------------
       Helpers – effective method, AJAX, headers()
       ----------------------------------------------------------------- */

    public function getEffectiveMethod(): string
    {
        if ($this->effectiveMethodCache !== null) {
            return $this->effectiveMethodCache;
        }

        $orig = strtoupper($this->method);
        if (!\in_array($orig, self::$validMethods, true)) {
            return $this->effectiveMethodCache = $orig;
        }

        return $this->effectiveMethodCache = match ($orig) {
            'HEAD'  => 'GET',
            'POST'  => $this->methodOverride() ?? 'POST',
            default => $orig
        };
    }

    private function methodOverride(): ?string
    {
        $hdr = $this->getHeaderLine('X-HTTP-Method-Override')
            ?: $this->getHeaderLine('HTTP-Method-Override');
        $cand = strtoupper($hdr ?: (string)$this->post('_method'));

        return \in_array($cand, self::$validMethods, true) ? $cand : null;
    }

    public function isAjax(): bool
    {
        $hdr = $this->server('HTTP_X_REQUESTED_WITH');
        return $hdr !== null && strcasecmp($hdr, 'xmlhttprequest') === 0;
    }

    /* expose RequestHeaders helper */
    public function headers(): RequestHeaders
    {
        return $this->hdrHelper ??= new RequestHeaders($this);
    }

    /* -----------------------------------------------------------------
       Convenience data accessors
       ----------------------------------------------------------------- */

    public function raw(): string
    {
        return $this->rawBodyCache ??= (string)$this->body;
    }

    public function server(?string $k = null): mixed
    {
        return $this->fetch($this->serverCol ??= new Collection($this->serverParams), $k);
    }

    public function cookie(?string $k = null): mixed
    {
        return $this->fetch($this->cookieCol ??= new Collection($this->cookieParams), $k);
    }

    public function query(?string $k = null): mixed
    {
        return $this->fetch($this->queryCol ??= new Collection($this->queryParams), $k);
    }

    public function post(?string $k = null): mixed
    {
        if (!$this->postCol) {
            $arr = \is_array($this->parsedBody) ? $this->parsedBody : [];
            $this->postCol = new Collection($arr);
        }
        return $this->fetch($this->postCol, $k);
    }

    public function parsedJson(?string $k = null): mixed
    {
        if (!$this->jsonCol) {
            $ct = $this->getHeaderLine('Content-Type');
            if (preg_match('#application/(.+\+)?json#i', $ct)) {
                $data = json_decode($this->raw(), true);
                if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                    throw new RuntimeException('Invalid JSON body');
                }
                $this->jsonCol = new Collection((array)$data);
            } else {
                $this->jsonCol = new Collection([]);
            }
        }
        return $this->jsonCol->isEmpty() ? $this->post($k) : $this->fetch($this->jsonCol, $k);
    }

    public function file(?string $k = null): mixed
    {
        return $this->fetch($this->filesCol ??= new Collection($this->uploadedFiles), $k);
    }

    private function fetch(Collection $c, ?string $k): mixed
    {
        return $k === null ? $c : ($c->$k ?? null);
    }

    public function __get(string $key): mixed
    {
        if (array_key_exists($key, $this->varMap)) {
            return $this->varMap[$key];
        }
        if ($this->checkEnv) {
            $env = getenv($key);
            if ($env !== false) {
                return $this->varMap[$key] = $env;
            }
        }
        return null;
    }

    /* -----------------------------------------------------------------
       Internal helpers
       ----------------------------------------------------------------- */

    private function normalizeHeaderName(string $n): string
    {
        return ucwords(strtolower($n), '-');
    }

    private function normalizeHeaders(array $h): array
    {
        $r = [];
        foreach ($h as $n => $v) {
            $r[$this->normalizeHeaderName($n)] = \is_array($v) ? array_values($v) : [(string)$v];
        }
        return $r;
    }
}
