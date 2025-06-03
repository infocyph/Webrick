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
 * PSR-7 ServerRequest + Webrick helpers
 *  • createFromGlobals()
 *  • method-override / AJAX / JSON helpers
 *  • magic __get() / __isset() honouring variables_order + request_order
 *  • RequestHeaders integration
 */
class ServerRequest implements ServerRequestInterface
{
    /* ----------  static factory  ---------------------------------- */

    public static function createFromGlobals(): static
    {
        $srv   = $_SERVER;
        $uri   = Uri::fromServerParams($srv);
        $meth  = $srv['REQUEST_METHOD'] ?? 'GET';
        $body  = new Stream(fopen('php://input', 'rb'));
        $proto = str_starts_with(($srv['SERVER_PROTOCOL'] ?? ''), 'HTTP/')
            ? substr($srv['SERVER_PROTOCOL'], 5)
            : '1.1';

        /* bootstrap w/ empty headers */
        $req = new static(
            $meth, $uri, $srv, [], $body, $proto, $_POST, $_FILES
        );

        /* populate headers via RequestHeaders */
        foreach ((new RequestHeaders($req))->all()->toArray() as $name => $val) {
            $req = $req->withHeader($name, is_array($val) ? $val : [(string) $val]);
        }

        /* parse url-encoded body for PUT/PATCH/DELETE */
        if (
            \in_array($req->method, ['PUT', 'PATCH', 'DELETE'], true) &&
            str_contains(strtolower($req->getHeaderLine('Content-Type')), 'application/x-www-form-urlencoded')
        ) {
            parse_str((string) $body, $form);
            $req = $req->withParsedBody($form);
        }

        /* query & cookie params */
        parse_str($uri->getQuery(), $q);
        return $req->withQueryParams($q)->withCookieParams($_COOKIE);
    }

    /* ----------  state  ------------------------------------------- */

    protected string       $method;
    protected UriInterface $uri;
    protected array        $cookieParams = [];
    protected array        $queryParams  = [];
    protected array        $attributes   = [];
    protected array        $headers      = [];

    /* caches & helpers */
    private ?Collection $queryCol  = null;
    private ?Collection $postCol   = null;
    private ?Collection $cookieCol = null;
    private ?Collection $serverCol = null;
    private ?Collection $jsonCol   = null;
    private ?Collection $xmlCol   = null;
    private ?Collection $filesCol  = null;
    private ?RequestHeaders $hdrHelper = null;
    private ?string $rawBodyCache  = null;
    private ?string $effectiveMethodCache = null;

    /* variable-order map */
    private array $varMap  = [];
    private bool  $checkEnv = false;

    /* valid HTTP verbs */
    private static array $validMethods = [
        'GET','POST','PUT','DELETE','PATCH','HEAD','OPTIONS','CONNECT','TRACE'
    ];

    /* ----------  constructor  ------------------------------------- */

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
            $h = $this->uri->getHost() . ($this->uri->getPort() ? ':' . $this->uri->getPort() : '');
            $this->headers['Host'] = [$h];
        }

        $this->buildVariableMap();           // build $varMap / $checkEnv
    }

    private function determineVariableOrder(): array
    {
        $vars = strtoupper(preg_replace('/[^EGPCS]/', '', ini_get('variables_order') ?: 'EGPCS'));
        $req  = strtoupper(preg_replace('/[^GPC]/', '', ini_get('request_order') ?: ''));

        $seq = str_split($vars);            // base order
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

    /** rebuild variable map after any mutator that changes G/P/C/S */
    private function buildVariableMap(): void
    {
        $this->composeVariableMap($this->determineVariableOrder());
    }

    /* ----------  MessageInterface  -------------------------------- */

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion($version): self
    {
        $c = clone $this;
        $c->protocolVersion = $version;
        return $c;
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
        $val  = is_array($value) ? array_values($value) : [(string) $value];
        if (($this->headers[$norm] ?? null) === $val) {
            return $this;
        }
        $c = clone $this;
        $c->headers[$norm] = $val;
        return $c;
    }

    public function withAddedHeader($name, $value): self
    {
        $norm = $this->normalizeHeaderName($name);
        $val  = is_array($value) ? $value : [(string) $value];
        if (!$this->hasHeader($norm)) {
            return $this->withHeader($norm, $val);
        }
        if ($val === array_intersect($val, $this->headers[$norm])) {
            return $this;
        } // already present
        $c = clone $this;
        $c->headers[$norm] = array_merge($this->headers[$norm], $val);
        return $c;
    }

    public function withoutHeader($name): self
    {
        $c = clone $this;
        unset($c->headers[$this->normalizeHeaderName($name)]);
        return $c;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): self
    {
        $c = clone $this;
        $c->body         = $body;
        $c->rawBodyCache = null;
        $c->jsonCol      = $c->xmlCol = null;   // reset both parsers
        return $c;
    }

    /* ----------  RequestInterface  -------------------------------- */

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

    public function withRequestTarget($requestTarget): self
    {
        if (preg_match('#\s#', $requestTarget)) {
            throw new InvalidArgumentException('Whitespace in requestTarget');
        }
        $c = clone $this;
        $c->requestTarget = $requestTarget;
        return $c;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod($method): self
    {
        $c = clone $this;
        $c->method = strtoupper($method);
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

    /* ----------  ServerRequestInterface  -------------------------- */

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
        $x->buildVariableMap();
        return $x;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function withQueryParams(array $query): self
    {
        $x = clone $this;
        $x->queryParams = $query;
        $x->queryCol = null;
        $x->buildVariableMap();
        return $x;
    }

    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    public function withUploadedFiles(array $uploadedFiles): self
    {
        array_walk_recursive($uploadedFiles, fn ($i) => $i instanceof UploadedFileInterface
            ?: throw new InvalidArgumentException('Invalid uploaded file'));
        $x = clone $this;
        $x->uploadedFiles = $uploadedFiles;
        $x->filesCol = null;
        return $x;
    }

    public function getParsedBody(): mixed
    {
        return $this->parsedBody;
    }

    public function withParsedBody($data): self
    {
        if ($data !== null && !\is_array($data) && !\is_object($data)) {
            throw new InvalidArgumentException('Parsed body must be array|object|null');
        }
        $x = clone $this;
        $x->parsedBody = $data;
        $x->postCol = $x->jsonCol = null;
        $x->buildVariableMap();
        return $x;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute($name, $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute($name, $value): self
    {
        $x = clone $this;
        $x->attributes[$name] = $value;
        return $x;
    }

    public function withoutAttribute($name): self
    {
        $x = clone $this;
        unset($x->attributes[$name]);
        return $x;
    }

    /* ----------  helpers  ----------------------------------------- */

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
            'HEAD' => 'GET',
            'POST' => $this->methodOverride() ?? 'POST',
            default => $orig
        };
    }

    private function methodOverride(): ?string
    {
        $hdr  = $this->getHeaderLine('X-HTTP-Method-Override')
            ?: $this->getHeaderLine('HTTP-Method-Override');
        $cand = strtoupper($hdr ?: (string) $this->post('_method'));

        return \in_array($cand, self::$validMethods, true) ? $cand : null;
    }

    public function isAjax(): bool
    {
        $hdr = $this->server('HTTP_X_REQUESTED_WITH');
        return $hdr !== null && strcasecmp($hdr, 'xmlhttprequest') === 0;
    }

    public function headers(): RequestHeaders
    {
        return $this->hdrHelper ??= new RequestHeaders($this);
    }

    /* ----------  convenience  ------------------------------------- */

    public function raw(): string
    {
        return $this->rawBodyCache ??= (string) $this->body;
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
            $this->postCol = new Collection(\is_array($this->parsedBody) ? $this->parsedBody : []);
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
                $this->jsonCol = new Collection((array) $data);
            } else {
                $this->jsonCol = new Collection([]);
            }
        }
        return $this->jsonCol->isEmpty() ? $this->post($k) : $this->fetch($this->jsonCol, $k);
    }

    /* place near parsedJson() */
    public function parsedXml(?string $key = null): mixed
    {
        if (!isset($this->xmlCol)) {
            $ct = $this->getHeaderLine('Content-Type');
            if (preg_match('#(application|text)/xml#i', $ct)) {
                $xml = @simplexml_load_string($this->raw(), 'SimpleXMLElement', LIBXML_NOERROR);
                $arr = $xml ? json_decode(json_encode($xml), true) : [];
                $this->xmlCol = new Collection((array) $arr);
            } else {
                $this->xmlCol = new Collection([]);
            }
        }
        return $this->xmlCol->isEmpty() ? $this->post($key) : $this->fetch($this->xmlCol, $key);
    }


    public function file(?string $k = null): mixed
    {
        return $this->fetch($this->filesCol ??= new Collection($this->uploadedFiles), $k);
    }

    private function fetch(Collection $c, ?string $k): mixed
    {
        return $k === null ? $c : ($c->$k ?? null);
    }

    /* ----------  magic getters  ----------------------------------- */

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

    /* ----------  internal helpers --------------------------------- */

    private function normalizeHeaderName(string $n): string
    {
        return ucwords(strtolower($n), '-');
    }

    private function normalizeHeaders(array $h): array
    {
        $r = [];
        foreach ($h as $n => $v) {
            $r[$this->normalizeHeaderName($n)] = \is_array($v) ? array_values($v) : [(string) $v];
        }
        return $r;
    }
}
