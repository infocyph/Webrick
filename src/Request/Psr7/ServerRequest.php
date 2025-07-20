<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Psr7;

use Infocyph\ArrayKit\Collection\Collection;
use Infocyph\Webrick\Request\Core\{Message, Stream, UploadedFile, Uri};
use Infocyph\Webrick\Request\Http\RequestHeaders;
use InvalidArgumentException;
use Psr\Http\Message\{ServerRequestInterface, StreamInterface, UploadedFileInterface, UriInterface};

/**
 * PSR-7 ServerRequest + Webrick sugar (2025 edition)
 *
 *  ✔ createFromGlobals()
 *  ✔ method-override, AJAX, JSON, XML helpers
 *  ✔ magic __get obeying variables_order & request_order
 *  ✔ RequestHeaders façade + ContentNegotiator, EndUser, …
 *  ✔ 100 % immutable         (every with*() clones)
 */
class ServerRequest extends Message implements ServerRequestInterface
{
    /* ======== 1.  Static factory  ====================================== */

    public static function createFromGlobals(): self
    {
        $srv = $_SERVER;
        $uri = Uri::fromServerParams($srv);

        $in = fopen('php://input', 'rb') ?: fopen('php://temp', 'rb');
        $body = new Stream($in);
        $httpVer = str_starts_with(($srv['SERVER_PROTOCOL'] ?? ''), 'HTTP/')
            ? substr((string)$srv['SERVER_PROTOCOL'], 5)
            : '1.1';

        /* build request (headers filled later in one go) */
        $req = new self(
            $srv['REQUEST_METHOD'] ?? 'GET',
            $uri,
            $srv,
            [],                         // headers added later
            $body,
            $httpVer,
            $_POST,
            self::normaliseFiles($_FILES),
        );

        /* 1) Import headers **once** (RequestHeaders also adds auth fall-backs) */
        $bag = new RequestHeaders($req)->all();
        $req->headers = $bag->all();

        /* 2) x-www-form-urlencoded body for verbs ≠ POST */
        if (
            in_array($req->method, ['PUT', 'PATCH', 'DELETE'], true) &&
            str_contains(strtolower($req->getHeaderLine('Content-Type')), 'application/x-www-form-urlencoded')
        ) {
            parse_str((string)$body, $form);
            $req = $req->withParsedBody($form);
        }

        /* 3) query + cookies */
        parse_str($uri->getQuery(), $qs);
        return $req
            ->withQueryParams($qs)
            ->withCookieParams($_COOKIE);
    }

    /* ======== 2.  Non-PSR state  ======================================= */

    private string $method;
    private UriInterface $uri;

    private array $server = [];
    private array $cookie = [];
    private array $query = [];
    private array $files = [];

    /** @var null|array|object */
    private null|array|object $parsed;

    private ?string $requestTarget = null;

    /* runtime caches */
    private ?RequestHeaders $hdrFacade = null;
    private ?Collection $jsonCol = null;
    private ?Collection $xmlCol = null;
    private ?string $rawBody = null;
    private ?string $effectiveMethod = null;

    /* variable-order map */
    private ?array $varMap = null;
    private bool $checkEnv = false;

    /** @var array<string,mixed> */
    private array $attributes = [];
    private array $filesSpec = [];
    private ?array $filesHydrated = null;

    /* Valid verbs */
    private const array VALID = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS', 'CONNECT', 'TRACE'];

    /* ======== 3.  Constructor (private, use factory) ==================== */

    public function __construct(
        string $method,
        UriInterface|string $uri,
        array $server = [],
        array $headers = [],
        StreamInterface $body = new Stream(),
        string $httpVer = '1.1',
        null|array|object $parsed = null,
        array $files = [],
        ?string $requestTarget = null,
    ) {
        parent::__construct($headers, $body, $httpVer);

        $this->method = strtoupper($method);
        $this->uri = $uri instanceof UriInterface ? $uri : new Uri($uri);
        $this->server = $server;
        $this->parsed = $parsed;
        $this->files = $files;
        $this->filesSpec = $files ?: $_FILES;
        $this->requestTarget = $requestTarget;

        /* copies of super-globals */
        $this->cookie = $_COOKIE;
        $this->query = $_GET;

        /* Host header fallback */
        if (!$this->hasHeader('Host') && $this->uri->getHost() !== '') {
            $this->headers['Host'] = [
                $this->uri->getHost()
                . ($this->uri->getPort() ? ':' . $this->uri->getPort() : ''),
            ];
        }

        $this->buildVariableMap();
    }

    /* ======== 4.  PSR-7 RequestInterface =============================== */

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

    public function withRequestTarget($t): static
    {
        if (preg_match('#\s#', $t)) {
            throw new InvalidArgumentException('Whitespace in request-target');
        }
        $c = clone $this;
        $c->requestTarget = $t;
        return $c;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod($m): static
    {
        $c = clone $this;
        $c->method = strtoupper($m);
        $c->effectiveMethod = null;
        return $c;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $u, $preserveHost = false): static
    {
        $c = clone $this;
        $c->uri = $u;
        if (!$preserveHost) {
            $c->headers['Host'] = $u->getHost()
                ? [$u->getHost() . ($u->getPort() ? ':' . $u->getPort() : '')]
                : [];
        } elseif ($u->getHost() && !$c->hasHeader('Host')) {
            $c->headers['Host'] = [$u->getHost()];
        }
        return $c;
    }

    /* ======== 5.  PSR-7 ServerRequestInterface ========================= */

    public function getServerParams(): array
    {
        return $this->server;
    }

    public function getCookieParams(): array
    {
        return $this->cookie;
    }

    public function withCookieParams(array $c): static
    {
        $cl = clone $this;
        $cl->cookie = $c;
        $cl->buildVariableMap();
        return $cl;
    }

    public function getQueryParams(): array
    {
        return $this->query;
    }

    public function withQueryParams(array $q): static
    {
        $cl = clone $this;
        $cl->query = $q;
        $cl->buildVariableMap();
        return $cl;
    }

    public function getUploadedFiles(): array
    {
        return $this->filesHydrated ??= self::normaliseFiles($this->filesSpec);
    }

    public function withUploadedFiles(array $files): static
    {
        $containsObjects = false;
        array_walk_recursive(
            $files,
            static function ($f) use (&$containsObjects): void {
                if ($f instanceof UploadedFileInterface) {
                    $containsObjects = true;
                }
            },
        );
        $cl = clone $this;
        if ($containsObjects) {
            $cl->filesHydrated = $files;
            $cl->filesSpec = [];          // no longer needed
        } else {
            $cl->filesSpec = $files;
            $cl->filesHydrated = null;        // will hydrate later
        }
        return $cl;
    }

    public function getParsedBody(): mixed
    {
        return $this->parsed;
    }

    public function withParsedBody($d): static
    {
        if ($d !== null && !is_array($d) && !is_object($d)) {
            throw new InvalidArgumentException('Parsed body must be array|object|null');
        }
        $cl = clone $this;
        $cl->parsed = $d;
        $cl->buildVariableMap();
        return $cl;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute($n, $def = null): mixed
    {
        return $this->attributes[$n] ?? $def;
    }

    public function withAttribute($n, $v): static
    {
        $cl = clone $this;
        $cl->attributes[$n] = $v;
        return $cl;
    }

    public function withoutAttribute($n): static
    {
        $cl = clone $this;
        unset($cl->attributes[$n]);
        return $cl;
    }

    /* ======== 6.  Helper façades ====================================== */

    public function headers(): RequestHeaders
    {
        return $this->hdrFacade ??= new RequestHeaders($this);
    }

    /* ---- raw / JSON / XML ------------------------------------------ */
    public function raw(): string
    {
        return $this->rawBody ??= (string)$this->body;
    }

    public function parsedJson(?string $key = null): mixed
    {
        if (!$this->jsonCol) {
            $ct = $this->getHeaderLine('Content-Type');
            if (preg_match('#application/(.+\+)?json#i', $ct)) {
                $arr = json_decode($this->raw(), true, 512, JSON_THROW_ON_ERROR);
                $this->jsonCol = new Collection((array)$arr);
            } else {
                $this->jsonCol = new Collection([]);
            }
        }
        return $this->jsonCol->isEmpty() ? $this->post($key) : $this->fetch($this->jsonCol, $key);
    }

    public function parsedXml(?string $key = null): mixed
    {
        if (!$this->xmlCol) {
            $ct = $this->getHeaderLine('Content-Type');
            if (preg_match('#(application|text)/xml#i', $ct)) {
                $xml = simplexml_load_string(
                    $this->raw(),
                    'SimpleXMLElement',
                    LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
                ) ?: null;
                $arr = $xml ? json_decode(json_encode($xml), true) : [];
                $this->xmlCol = new Collection((array)$arr);
            } else {
                $this->xmlCol = new Collection([]);
            }
        }
        return $this->xmlCol->isEmpty() ? $this->post($key) : $this->fetch($this->xmlCol, $key);
    }

    /* ---- method-override / AJAX / negotiation ---------------------- */
    public function getEffectiveMethod(): string
    {
        if ($this->effectiveMethod) {
            return $this->effectiveMethod;
        }
        $verb = strtoupper($this->method);
        if (!in_array($verb, self::VALID, true)) {
            return $this->effectiveMethod = $verb;          // REPORT / SEARCH …
        }
        return $this->effectiveMethod = match ($verb) {
            'HEAD' => 'GET',
            'POST' => $this->methodOverride() ?? 'POST',
            default => $verb
        };
    }

    private function methodOverride(): ?string
    {
        $hdr = $this->getHeaderLine('X-HTTP-Method-Override')
            ?: $this->getHeaderLine('HTTP-Method-Override');
        $cand = strtoupper($hdr ?: (string)$this->post('_method'));

        return in_array($cand, self::VALID, true) ? $cand : null;
    }

    public function isAjax(): bool
    {
        $hdr = $this->server('HTTP_X_REQUESTED_WITH')
            ?: $this->getHeaderLine('X-Requested-With');
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

    /* ---- super-global style helpers -------------------------------- */
    public function server(?string $k = null): mixed
    {
        return $this->fetch(new Collection($this->server), $k);
    }

    public function cookie(?string $k = null): mixed
    {
        return $this->fetch(new Collection($this->cookie), $k);
    }

    public function query(?string $k = null): mixed
    {
        return $this->fetch(new Collection($this->query), $k);
    }

    public function post(?string $k = null): mixed
    {
        $col = new Collection(is_array($this->parsed) ? $this->parsed : []);
        return $this->fetch($col, $k);
    }

    public function file(?string $k = null): mixed
    {
        return $this->fetch(new Collection($this->files), $k);
    }

    private function fetch(Collection $c, ?string $k): mixed
    {
        return $k === null ? $c : ($c->$k ?? null);
    }

    /* magic variable-order map ------------------------------------------------ */
    public function __get(string $key): mixed
    {
        if (array_key_exists($key, $this->varMap)) {
            return $this->varMap[$key];
        }
        if ($this->checkEnv && ($e = getenv($key)) !== false) {
            return $this->varMap[$key] = $e;
        }
        return null;
    }

    public function __isset(string $key): bool
    {
        return $this->__get($key) !== null;
    }

    /* ======== 7.  Internals =============================================== */

    private static function normaliseFiles(array $spec): array
    {
        if ($spec === []) {
            return [];
        }
        $out = [];
        foreach ($spec as $name => $part) {
            if ($part instanceof UploadedFileInterface) {
                $out[$name] = $part;
                continue;
            }
            if (is_array($part['tmp_name'])) {                 // nested array (multi upload)
                $out[$name] = self::unwindNestedFiles($part);
                continue;
            }
            $out[$name] = new UploadedFile(
                $part['tmp_name'],
                $part['size'] ?? null,
                $part['error'] ?? 0,
                $part['name'] ?? null,
                $part['type'] ?? null,
            );
        }
        return $out;
    }

    private static function unwindNestedFiles(array $bag): array
    {
        $out = [];
        foreach ($bag['tmp_name'] as $idx => $_) {
            $spec = [
                'tmp_name' => $bag['tmp_name'][$idx],
                'size' => $bag['size'][$idx],
                'error' => $bag['error'][$idx],
                'name' => $bag['name'][$idx],
                'type' => $bag['type'][$idx],
            ];
            $out[$idx] = is_array($spec['tmp_name'])
                ? self::unwindNestedFiles($spec)               // deeper level
                : new UploadedFile(...$spec);
        }
        return $out;
    }

    /* variable-order helpers ---------------------------------------------- */
    private function buildVariableMap(): void
    {
        if ($this->varMap !== null) {
            return;           // hot-path: one pointer read
        }

        /** Cache the resolved order for the lifetime of the PHP process */
        static $SEQ = null;
        $seq = $SEQ ??= $this->determineVariableOrder();

        // Hot-path source table (no ENV yet – handled inline)
        $src = [
            'G' => $this->query,
            'P' => is_array($this->parsed) ? $this->parsed : [],
            'C' => $this->cookie,
            'S' => $this->server,
        ];

        $map = [];
        foreach ($seq as $ch) {
            if ($ch === 'E') {           // defer $_ENV until really requested
                $map += $_ENV;
                continue;
            }
            if (isset($src[$ch])) {
                $map += $src[$ch];       // “+” keeps earlier values (correct precedence)
            }
        }

        $this->varMap = $map;
        $this->checkEnv = in_array('E', $seq, true);
    }

    private function determineVariableOrder(): array
    {
        $vars = strtoupper(preg_replace('/[^EGPCS]/', '', ini_get('variables_order') ?: 'EGPCS'));
        $req = strtoupper(preg_replace('/[^GPC]/', '', ini_get('request_order') ?: ''));

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
}
