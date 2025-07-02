<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request;

use Infocyph\ArrayKit\Collection\Collection;
use InvalidArgumentException;
use Psr\Http\Message\{ServerRequestInterface, StreamInterface, UploadedFileInterface, UriInterface};
use RuntimeException;

/**
 * PSR-7 ServerRequest + Webrick helpers
 *
 *  • createFromGlobals()
 *  • method-override, AJAX, JSON & XML helpers
 *  • magic __get/__isset obeying variables_order & request_order
 *  • RequestHeaders façade
 *  • Everything 100 % immutable (every with*() clones)
 */
class ServerRequest implements ServerRequestInterface
{
    /* -----------------------------------------------------------------
     *  Static factory
     * ---------------------------------------------------------------- */
    public static function createFromGlobals(): self
    {
        $srv   = $_SERVER;
        $uri   = Uri::fromServerParams($srv);
        $in = fopen('php://input', 'rb');
        if ($in === false) {
            $in = fopen('php://temp', 'rb');
        }
        $body = new Stream($in);
        $proto = str_starts_with(($srv['SERVER_PROTOCOL'] ?? ''), 'HTTP/')
            ? substr((string)$srv['SERVER_PROTOCOL'], 5)
            : '1.1';

        $req = new self(
            $srv['REQUEST_METHOD'] ?? 'GET',
            $uri,
            $srv,
            [],              // headers added next
            $body,
            $proto,
            $_POST,
            $_FILES
        );

        /* import request headers */
        foreach ((new RequestHeaders($req))->all()->toArray() as $name => $val) {
            $req = $req->withHeader($name, is_array($val) ? $val : [(string)$val]);
        }

        /* x-www-form-urlencoded for verbs ≠ POST */
        if (
            \in_array($req->method, ['PUT', 'PATCH', 'DELETE'], true) &&
            str_contains(strtolower($req->getHeaderLine('Content-Type')), 'application/x-www-form-urlencoded')
        ) {
            parse_str((string)$body, $form);
            $req = $req->withParsedBody($form);
        }

        parse_str($uri->getQuery(), $queryParams);
        return $req
            ->withQueryParams($queryParams)
            ->withCookieParams($_COOKIE);
    }

    /* -----------------------------------------------------------------
     *  State
     * ---------------------------------------------------------------- */
    private string               $method;
    private UriInterface         $uri;

    private array $headers       = [];
    private array $serverParams  = [];
    private array $cookieParams  = [];
    private array $queryParams   = [];
    private array $uploadedFiles = [];
    private array $attributes    = [];

    private StreamInterface      $body;
    private string               $protocolVer;
    private null|array|object    $parsedBody;
    private ?string              $requestTarget;

    /*  caches & helpers  */
    private ?Collection     $queryCol  = null;
    private ?Collection     $postCol   = null;
    private ?Collection     $cookieCol = null;
    private ?Collection     $serverCol = null;
    private ?Collection     $jsonCol   = null;
    private ?Collection     $xmlCol    = null;
    private ?Collection     $filesCol  = null;
    private ?RequestHeaders $hdrHelper = null;
    private ?string         $rawBodyCache = null;
    private ?string         $effectiveMethodCache = null;

    /*  variable-order map */
    private array $varMap = [];
    private bool  $checkEnv = false;

    /* -----------------------------------------------------------------
     *  Valid HTTP verbs
     * ---------------------------------------------------------------- */
    private const VALID_METHODS = [
        'GET','POST','PUT','DELETE','PATCH','HEAD','OPTIONS','CONNECT','TRACE'
    ];

    /* -----------------------------------------------------------------
     *  Constructor
     * ---------------------------------------------------------------- */
    public function __construct(
        string                   $method,
        UriInterface|string      $uri,
        array                    $serverParams = [],
        array                    $headers      = [],
        StreamInterface          $body         = new Stream(),
        string                   $protocolVer  = '1.1',
        null|array|object        $parsedBody   = null,
        array                    $uploaded     = [],
        ?string                  $requestTarget = null
    ) {
        $this->method        = strtoupper($method);
        $this->uri           = $uri instanceof UriInterface ? $uri : new Uri($uri);

        $this->serverParams  = $serverParams;
        $this->headers       = $this->normalizeHeaders($headers);
        $this->body          = $body;
        $this->protocolVer   = $protocolVer;
        $this->parsedBody    = $parsedBody;
        $this->uploadedFiles = $uploaded;
        $this->requestTarget = $requestTarget;

        if (!$this->hasHeader('Host') && $this->uri->getHost() !== '') {
            $this->headers['Host'] = [$this->uri->getHost() .
                ($this->uri->getPort() ? ':' . $this->uri->getPort() : '')];
        }

        /* copies of super-globals (never mutate the originals!) */
        $this->cookieParams = $_COOKIE;
        $this->queryParams  = $_GET;

        $this->buildVariableMap();
    }

    /* ===============================================================
       MessageInterface
       ============================================================== */
    public function getProtocolVersion(): string
    {
        return $this->protocolVer;
    }
    public function withProtocolVersion($v): self
    {
        $x = clone $this;
        $x->protocolVer = $v;
        return $x;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
    public function hasHeader($n): bool
    {
        return isset($this->headers[$this->norm($n)]);
    }
    public function getHeader($n): array
    {
        return $this->headers[$this->norm($n)] ?? [];
    }
    public function getHeaderLine($n): string
    {
        return implode(',', $this->getHeader($n));
    }

    public function withHeader($n, $v): self
    {
        $norm = $this->norm($n);
        $val  = is_array($v) ? array_values($v) : [(string)$v];
        if (($this->headers[$norm] ?? null) === $val) {
            return $this;
        }

        $x = clone $this;
        $x->headers[$norm] = $val;
        return $x;
    }

    public function withAddedHeader($n, $v): self
    {
        $norm = $this->norm($n);
        $val  = is_array($v) ? $v : [(string)$v];

        if (!$this->hasHeader($norm)) {
            return $this->withHeader($norm, $val);
        }
        if ($val === array_intersect($val, $this->headers[$norm])) {
            return $this;                     // already present
        }
        $x = clone $this;
        $x->headers[$norm] = array_merge($this->headers[$norm], $val);
        return $x;
    }

    public function withoutHeader($n): self
    {
        $x = clone $this;
        unset($x->headers[$this->norm($n)]);
        return $x;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }
    public function withBody(StreamInterface $b): self
    {
        $x = clone $this;
        $x->body          = $b;
        $x->rawBodyCache  = null;
        $x->jsonCol = $x->xmlCol = null;
        return $x;
    }

    /* ===============================================================
       RequestInterface
       ============================================================== */
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

    public function withRequestTarget($t): self
    {
        if (preg_match('#\s#', $t)) {
            throw new InvalidArgumentException('Whitespace in request-target');
        }
        $x = clone $this;
        $x->requestTarget = $t;
        return $x;
    }

    public function getMethod(): string
    {
        return $this->method;
    }
    public function withMethod($m): self
    {
        $x = clone $this;
        $x->method = strtoupper($m);
        $x->effectiveMethodCache = null;
        return $x;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }
    public function withUri(UriInterface $u, $preserveHost = false): self
    {
        $x = clone $this;
        $x->uri = $u;
        if (!$preserveHost) {
            $x->headers['Host'] = $u->getHost()
                ? [$u->getHost() . ($u->getPort() ? ':' . $u->getPort() : '')]
                : [];
        } elseif ($u->getHost() && !$x->hasHeader('Host')) {
            $x->headers['Host'] = [$u->getHost()];
        }
        return $x;
    }

    /* ===============================================================
       ServerRequestInterface
       ============================================================== */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }
    public function withCookieParams(array $c): self
    {
        $x = clone $this;
        $x->cookieParams = $c;
        $x->cookieCol = null;
        $x->buildVariableMap();
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
        $x->buildVariableMap();
        return $x;
    }

    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }
    public function withUploadedFiles(array $u): self
    {
        array_walk_recursive(
            $u,
            static fn ($i) =>
        $i instanceof UploadedFileInterface
            ?: throw new InvalidArgumentException('Invalid uploaded file')
        );
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
        if ($d !== null && !\is_array($d) && !\is_object($d)) {
            throw new InvalidArgumentException('Parsed body must be array|object|null');
        }
        $x = clone $this;
        $x->parsedBody = $d;
        $x->postCol = $x->jsonCol = null;
        $x->buildVariableMap();
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

    /* ===============================================================
       Helpers & sugar
       ============================================================== */

    /* ---- RequestHeaders façade ----------------------------------- */
    public function headers(): RequestHeaders
    {
        return $this->hdrHelper ??= new RequestHeaders($this);
    }

    /* ---- raw / JSON / XML ---------------------------------------- */
    public function raw(): string
    {
        return $this->rawBodyCache ??= (string)$this->body;
    }

    public function parsedJson(?string $key = null): mixed
    {
        if (!$this->jsonCol) {
            $ct = $this->getHeaderLine('Content-Type');
            if (preg_match('#application/(.+\+)?json#i', $ct)) {
                try {
                    $data = json_decode(
                        $this->raw(),
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                } catch (\JsonException $e) {
                    throw new RuntimeException('Invalid JSON body: ' . $e->getMessage(), 0, $e);
                }
                $this->jsonCol = new Collection((array)$data);
            } else {
                $this->jsonCol = new Collection([]);
            }
        }
        return $this->jsonCol->isEmpty()
            ? $this->post($key)
            : $this->fetch($this->jsonCol, $key);
    }

    public function parsedXml(?string $key = null): mixed
    {
        if (!$this->xmlCol) {
            $ct = $this->getHeaderLine('Content-Type');
            if (preg_match('#(application|text)/xml#i', $ct)) {
                $prev = libxml_disable_entity_loader(true);
                $xml  = @simplexml_load_string(
                    $this->raw(),
                    'SimpleXMLElement',
                    LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
                );
                libxml_disable_entity_loader($prev);
                $arr = $xml ? json_decode(json_encode($xml), true) : [];
                $this->xmlCol = new Collection((array)$arr);
            } else {
                $this->xmlCol = new Collection([]);
            }
        }
        return $this->xmlCol->isEmpty()
            ? $this->post($key)
            : $this->fetch($this->xmlCol, $key);
    }

    /* ---- method-override / AJAX / negotiation -------------------- */
    public function getEffectiveMethod(): string
    {
        if ($this->effectiveMethodCache !== null) {
            return $this->effectiveMethodCache;
        }

        $verb = strtoupper($this->method);
        if (!\in_array($verb, self::VALID_METHODS, true)) {
            return $this->effectiveMethodCache = $verb;
        }
        return $this->effectiveMethodCache = match($verb) {
            'HEAD' => 'GET',
            'POST' => $this->methodOverride() ?? 'POST',
            default => $verb
        };
    }

    private function methodOverride(): ?string
    {
        $hdr  = $this->getHeaderLine('X-HTTP-Method-Override')
            ?: $this->getHeaderLine('HTTP-Method-Override');
        $cand = strtoupper($hdr ?: (string)$this->post('_method'));

        return \in_array($cand, self::VALID_METHODS, true) ? $cand : null;
    }

    public function isAjax(): bool
    {
        $hdr = $this->server('HTTP_X_REQUESTED_WITH');
        return $hdr !== null && strcasecmp((string)$hdr, 'xmlhttprequest') === 0;
    }

    public function expectsJson(): bool
    {
        return str_contains($this->getHeaderLine('Accept'), 'json') || $this->isAjax();
    }

    public function expectsXml(): bool
    {
        return str_contains($this->getHeaderLine('Accept'), 'xml');
    }

    /* ---- super-global style helpers ------------------------------ */
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
            $this->postCol = new Collection(\is_array($this->parsedBody) ? $this->parsedBody : []);
        }
        return $this->fetch($this->postCol, $k);
    }

    public function file(?string $k = null): mixed
    {
        return $this->fetch($this->filesCol ??= new Collection($this->uploadedFiles), $k);
    }

    private function fetch(Collection $c, ?string $k): mixed
    {
        return $k === null ? $c : ($c->$k ?? null);
    }

    /* ---- magic variable map -------------------------------------- */
    public function __get(string $key): mixed
    {
        if (\array_key_exists($key, $this->varMap)) {
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
    public function __isset(string $key): bool
    {
        return $this->__get($key) !== null;
    }

    /* -----------------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------------- */
    private function norm(string $n): string
    {
        return ucwords(strtolower($n), '-');
    }
    private function normalizeHeaders(array $h): array
    {
        $out = [];
        foreach ($h as $n => $v) {
            $out[$this->norm($n)] = \is_array($v) ? array_values($v) : [(string)$v];
        }
        return $out;
    }

    /* ---- variable-order helpers ---------------------------------- */
    private function buildVariableMap(): void
    {
        $this->composeVariableMap($this->determineVariableOrder());
    }

    private function determineVariableOrder(): array
    {
        $vars = strtoupper(preg_replace('/[^EGPCS]/', '', ini_get('variables_order') ?: 'EGPCS'));
        $req  = strtoupper(preg_replace('/[^GPC]/', '', ini_get('request_order') ?: ''));

        $seq = str_split($vars);
        if ($req !== '') {
            $seq = array_values(array_diff($seq, ['G','P','C']));
            $anchor = array_search('E', $seq, true);
            $insert = $anchor === false ? 0 : $anchor + 1;

            foreach (array_reverse(str_split($req)) as $ch) {
                array_splice($seq, $insert, 0, $ch);
            }
        }
        return $seq;
    }

    private function composeVariableMap(array $order): void
    {
        $src = [
            'G' => $this->queryParams,
            'P' => (\is_array($this->parsedBody) ? $this->parsedBody : []),
            'C' => $this->cookieParams,
            'S' => $this->serverParams,
        ];

        $map = [];
        foreach ($order as $ch) {
            if (isset($src[$ch])) {
                $map += $src[$ch];
            }
        }
        $this->varMap   = $map;
        $this->checkEnv = \in_array('E', $order, true);
    }
}
