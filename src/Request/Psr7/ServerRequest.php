<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Psr7;

use Infocyph\ArrayKit\Collection\Collection;
use Infocyph\Webrick\Request\Core\{Message, Stream, UploadedFile, UploadedFileCollection, Uri};
use Infocyph\Webrick\Request\Http\RequestHeaders;
use InvalidArgumentException;

/**
 * PSR-7 ServerRequest + Webrick sugar (2025 edition)
 *
 *  ✔ createFromGlobals()
 *  ✔ method-override, AJAX, JSON, XML helpers
 *  ✔ magic __get obeying variables_order & request_order
 *  ✔ RequestHeaders façade + ContentNegotiator, EndUser, …
 *  ✔ 100 % immutable         (every with*() clones)
 */
class ServerRequest extends Message
{

    /* ======== 2.  Non-PSR state  ======================================= */

    private string $method;
    private Uri $uri;

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
    private static bool $methodParamOverride = false;

    /* Valid verbs */
    private const array VALID = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS', 'CONNECT', 'TRACE'];

    /* ======== 3.  Constructor (private, use factory) ==================== */

    public function __construct(
        string $method,
        Uri|string $uri,
        array $server = [],
        array $headers = [],
        Stream $body = new Stream(),
        string $httpVer = '1.1',
        null|array|object $parsed = null,
        array $files = [],
        ?string $requestTarget = null,
    ) {
        parent::__construct($headers, $body, $httpVer);

        $this->method = strtoupper($method);
        $this->uri = $uri instanceof Uri ? $uri : new Uri($uri);
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

    /* ======== 1.  Static factory  ====================================== */

    public static function createFromGlobals(): static
    {
        $srv = $_SERVER;
        $uri = Uri::fromServerParams($srv);
        $body = self::openInputStream();
        $httpVer = self::detectHttpVersion($srv);
        $headers = RequestHeaders::extractFromServer($srv);

        // build request (headers re-imported once below)
        $req = new static(
            $srv['REQUEST_METHOD'] ?? 'GET',
            $uri,
            $srv,
            $headers,
            $body,
            $httpVer,
            $_POST,
            self::normaliseFiles($_FILES),
        );

        $req = self::importHeadersOnce($req);
        $req = self::maybeParseUrlEncodedForNonPost($req, $body);
        return self::attachQueryAndCookies($req, $uri);
    }

    private static function openInputStream(): Stream
    {
        $in = fopen('php://input', 'rb') ?: fopen('php://temp', 'rb');
        return new Stream($in);
    }

    private static function detectHttpVersion(array $srv): string
    {
        $proto = (string)($srv['SERVER_PROTOCOL'] ?? '');
        return str_starts_with($proto, 'HTTP/') ? substr($proto, 5) : '1.1';
    }

    /** Import headers exactly once (includes auth fallbacks via RequestHeaders). */
    private static function importHeadersOnce(self $req): self
    {
        $bag = new RequestHeaders($req)->all();
        $req->headers = $bag->all();   // protected prop on parent; same class context
        return $req;
    }

    /** Parse application/x-www-form-urlencoded for PUT/PATCH/DELETE */
    private static function maybeParseUrlEncodedForNonPost(self $req, Stream $body): self
    {
        if (
            in_array($req->method, ['PUT', 'PATCH', 'DELETE'], true) &&
            str_contains(strtolower($req->getHeaderLine('Content-Type')), 'application/x-www-form-urlencoded')
        ) {
            parse_str((string)$body, $form);
            $req = $req->withParsedBody($form);
        }
        return $req;
    }

    private static function attachQueryAndCookies(self $req, Uri $uri): self
    {
        parse_str($uri->getQuery(), $qs);
        return $req
            ->withQueryParams($qs)
            ->withCookieParams($_COOKIE);
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

    public function withRequestTarget($requestTarget): static
    {
        if (preg_match('#\s#', $requestTarget)) {
            throw new InvalidArgumentException('Whitespace in request-target');
        }
        $c = clone $this;
        $c->requestTarget = $requestTarget;
        return $c;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod($method): static
    {
        $c = clone $this;
        $c->method = strtoupper($method);
        $c->effectiveMethod = null;
        return $c;
    }

    public function getUri(): Uri
    {
        return $this->uri;
    }

    public function withUri(Uri $uri, $preserveHost = false): static
    {
        $c = clone $this;
        $c->uri = $uri;
        if (!$preserveHost) {
            $c->headers['Host'] = $uri->getHost()
                ? [$uri->getHost() . ($uri->getPort() ? ':' . $uri->getPort() : '')]
                : [];
        } elseif ($uri->getHost() && !$c->hasHeader('Host')) {
            $c->headers['Host'] = [$uri->getHost()];
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

    public function withCookieParams(array $cookies): static
    {
        $cl = clone $this;
        $cl->cookie = $cookies;
        $cl->buildVariableMap();
        return $cl;
    }

    public function getQueryParams(): array
    {
        return $this->query;
    }

    public function withQueryParams(array $query): static
    {
        $cl = clone $this;
        $cl->query = $query;
        $cl->buildVariableMap();
        return $cl;
    }

    public function getUploadedFiles(): array
    {
        if ($this->filesHydrated !== null) {
            return $this->filesHydrated;
        }
        return $this->filesHydrated = self::normaliseFiles($this->filesSpec);
    }

    public function withUploadedFiles(array $uploadedFiles): static
    {
        $cl = clone $this;
        $cl->filesSpec = $uploadedFiles;
        $cl->filesHydrated = null;
        return $cl;
    }

    public function getUploadedFilesCollection(): UploadedFileCollection
    {
        return $this->filesColl ??= new UploadedFileCollection($this->getUploadedFiles());
    }

    public function getParsedBody(): array|null|object
    {
        return $this->parsed;
    }

    public function withParsedBody($data): static
    {
        if ($data !== null && !is_array($data) && !is_object($data)) {
            throw new InvalidArgumentException('Parsed body must be array|object|null');
        }
        $cl = clone $this;
        $cl->parsed = $data;
        $cl->buildVariableMap();
        return $cl;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute($name, $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute($name, $value): static
    {
        $cl = clone $this;
        $cl->attributes[$name] = $value;
        return $cl;
    }

    public function withoutAttribute($name): static
    {
        $cl = clone $this;
        unset($cl->attributes[$name]);
        return $cl;
    }

    /* ======== 6.  Helper façades ====================================== */

    public static function setMethodParamOverride(bool $enabled): void
    {
        self::$methodParamOverride = $enabled;
    }

    /** Check whether the override is currently allowed. */
    public static function getMethodParamOverride(): bool
    {
        return self::$methodParamOverride;
    }

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

    private function isFormPost(): bool
    {
        if (strtoupper($this->method) !== 'POST') {
            return false;
        }
        $ct = strtolower($this->getHeaderLine('Content-Type'));
        return str_starts_with($ct, 'application/x-www-form-urlencoded')
            || str_starts_with($ct, 'multipart/form-data');
    }


    private function methodOverride(): ?string
    {
        // 1) Header-based override (always honored)
        $hdr = $this->getHeaderLine('X-HTTP-Method-Override')
            ?: $this->getHeaderLine('HTTP-Method-Override');

        if ($hdr !== '') {
            $cand = strtoupper($hdr);
            return in_array($cand, self::VALID, true) ? $cand : null;
        }

        // 2) Form parameter `_method` is gated + only for POST form submissions
        if (self::$methodParamOverride && $this->isFormPost()) {
            $cand = strtoupper((string)$this->post('_method'));
            return in_array($cand, self::VALID, true) ? $cand : null;
        }

        return null;
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

    public function file(?string $key = null): UploadedFile|array|null
    {
        $files = $this->getUploadedFiles();
        return $key === null ? $files : ($files[$key] ?? null);
    }

    public function files(): UploadedFileCollection
    {
        return $this->getUploadedFilesCollection();
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
            if ($part instanceof UploadedFile) {
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
