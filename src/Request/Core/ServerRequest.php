<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Core;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Request\Http\RequestHeaders;
use Infocyph\Webrick\Support\HttpUtils;
use InvalidArgumentException;

/** Native Webrick server-request model. */
class ServerRequest extends Message
{
    private const array VALID = [HttpMethodEnum::GET->value,HttpMethodEnum::POST->value,HttpMethodEnum::PUT->value,HttpMethodEnum::DELETE->value,HttpMethodEnum::PATCH->value,HttpMethodEnum::HEAD->value,HttpMethodEnum::OPTIONS->value,HttpMethodEnum::CONNECT->value,HttpMethodEnum::TRACE->value];

    private static bool $methodOverrideConfigurationFrozen = false;

    private static bool $methodParamOverride = false;

    /** @var array<string,mixed> */
    private array $attributes = [];

    private bool $checkEnv = false;

    /** @var array<string,mixed> */
    private array $cookie;

    private ?string $effectiveMethod = null;

    private ?UploadedFileCollection $filesCollection = null;

    /** @var array<string,UploadedFile|array<array-key,mixed>>|null */
    private ?array $filesHydrated = null;

    /** @var array<string,mixed> */
    private array $filesSpec;

    private ?RequestHeaders $headersFacade = null;

    private string $method;

    /** @var array<string,mixed> */
    private array $query;

    private ?string $rawBody = null;

    private Uri $uri;

    /** @var array<string,mixed>|null */
    private ?array $variableMap = null;

    /** @param array<string,mixed> $server @param array<string,string|list<string>> $headers @param array<string,mixed>|object|null $parsed @param array<string,mixed> $files @param array<string,mixed>|null $query @param array<string,mixed> $cookies */
    public function __construct(string $method, Uri|string $uri, private array $server = [], array $headers = [], ?BodyStream $body = null, string $httpVer = '1.1', private array|object|null $parsed = null, array $files = [], private ?string $requestTarget = null, ?array $query = null, array $cookies = [])
    {
        parent::__construct($headers, $body, $httpVer);
        $this->method = HttpMethodEnum::normalize($method);
        $this->uri = $uri instanceof Uri ? $uri : new Uri($uri);
        $this->server = self::stringMap($server);
        $this->filesSpec = self::stringMap($files);
        $this->cookie = self::stringMap($cookies);
        $this->query = server_request_query_parameters($query, $this->uri);
        if (!$this->hasHeader('Host') && $this->uri->getHost() !== '') {
            $host = $this->uri->getHost();
            if ($this->uri->getPort() !== null) {
                $host .= ':' . $this->uri->getPort();
            }
            $this->headers['Host'] = [$host];
        }
    }

    public function __get(string $key): mixed
    {
        $this->buildVariableMap();
        if (array_key_exists($key, $this->variableMap ?? [])) {
            return $this->variableMap[$key];
        }
        if ($this->checkEnv && ($value = getenv($key)) !== false) {
            return $this->variableMap[$key] = $value;
        }

        return null;
    }

    public function __isset(string $key): bool
    {
        return $this->__get($key) !== null;
    }

    public function __set(string $name, mixed $value): void
    {
        throw new InvalidArgumentException('Request is immutable');
    }

    public static function createFromGlobals(): self
    {
        $server = self::stringMap($_SERVER);
        $bodyHandle = fopen('php://input', 'rb') ?: fopen('php://temp', 'rb');
        if (!is_resource($bodyHandle)) {
            throw new \RuntimeException('Unable to open request input stream.');
        }
        $body = new Stream($bodyHandle);
        $protocol = self::serverString($server, 'SERVER_PROTOCOL');
        $httpVersion = str_starts_with($protocol, 'HTTP/') ? substr($protocol, 5) : '1.1';
        $request = new self(self::serverString($server, 'REQUEST_METHOD', HttpMethodEnum::GET->value), Uri::fromServerParams($server), $server, RequestHeaders::extractFromServer($server), $body, $httpVersion, self::stringMap($_POST), self::stringMap($_FILES), query: $_GET !== [] ? self::stringMap($_GET) : null, cookies: self::stringMap($_COOKIE));
        if (in_array($request->method, [HttpMethodEnum::PUT->value,HttpMethodEnum::PATCH->value,HttpMethodEnum::DELETE->value], true) && str_contains(strtolower($request->getHeaderLine('Content-Type')), MediaTypeEnum::FORM_URLENCODED->value)) {
            parse_str((string) $body, $form);
            $request = $request->withParsedBody(self::stringMap($form));
        }

        return $request;
    }

    public static function freezeMethodOverrideConfiguration(): void
    {
        self::$methodOverrideConfigurationFrozen = true;
    }

    public static function getMethodParamOverride(): bool
    {
        return self::$methodParamOverride;
    }

    public static function setMethodParamOverride(bool $enabled): void
    {
        if (self::$methodOverrideConfigurationFrozen) {
            throw new \LogicException('Method-override configuration is frozen for production runtime.');
        }
        self::$methodParamOverride = $enabled;
    }

    public function expectsJson(): bool
    {
        return str_contains(strtolower($this->getHeaderLine('Accept')), 'json') || $this->isAjax();
    }

    public function expectsXml(): bool
    {
        return str_contains(strtolower($this->getHeaderLine('Accept')), 'xml');
    }

    /** @return UploadedFile|array<array-key,mixed>|null */
    public function file(?string $key = null): UploadedFile|array|null
    {
        $files = $this->getUploadedFiles();
        $value = $key === null ? $files : ($files[$key] ?? null);

        return is_array($value) || $value instanceof UploadedFile ? $value : null;
    }

    public function files(): UploadedFileCollection
    {
        return $this->getUploadedFilesCollection();
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    /** @return array<string,mixed> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /** @return array<string,mixed> */
    public function getCookieParams(): array
    {
        return $this->cookie;
    }

    public function getEffectiveMethod(): string
    {
        if ($this->effectiveMethod !== null) {
            return $this->effectiveMethod;
        }
        $verb = $this->method;
        if (!in_array($verb, self::VALID, true)) {
            return $this->effectiveMethod = $verb;
        }

        return $this->effectiveMethod = match ($verb) {
            HttpMethodEnum::HEAD->value => HttpMethodEnum::GET->value,HttpMethodEnum::POST->value => $this->methodOverride() ?? HttpMethodEnum::POST->value,default => $verb
        };
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    /** @return array<string,mixed>|object|null */
    public function getParsedBody(): array|object|null
    {
        return $this->parsed;
    }

    /** @return array<string,mixed> */
    public function getQueryParams(): array
    {
        return $this->query;
    }

    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        } $target = $this->uri->getPath() ?: '/';
        if ($this->uri->getQuery() !== '') {
            $target .= '?' . $this->uri->getQuery();
        }

return $target;
    }

    /** @return array<string,mixed> */
    public function getServerParams(): array
    {
        return $this->server;
    }

    /** @return array<string,UploadedFile|array<mixed>> */
    public function getUploadedFiles(): array
    {
        return $this->filesHydrated ??= UploadedFilesNormalizer::normalise($this->filesSpec);
    }

    public function getUploadedFilesCollection(): UploadedFileCollection
    {
        return $this->filesCollection ??= new UploadedFileCollection($this->getUploadedFiles());
    }

    public function getUri(): Uri
    {
        return $this->uri;
    }

    public function headers(): RequestHeaders
    {
        return $this->headersFacade ??= new RequestHeaders($this);
    }

    public function isAjax(): bool
    {
        $header = $this->server['HTTP_X_REQUESTED_WITH'] ?? $this->getHeaderLine('X-Requested-With');

        return is_string($header) && strcasecmp($header, 'xmlhttprequest') === 0;
    }

    public function raw(): string
    {
        return $this->rawBody ??= (string) $this->body;
    }

    public function withAttribute(string $name, mixed $value): static
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;

        return $clone;
    }

    /** @param array<string,mixed> $attributes */
    public function withAttributes(array $attributes): static
    {
        if ($attributes === []) {
            return $this;
        } $clone = clone $this;
        foreach ($attributes as $name => $value) {
            $clone->attributes[$name] = $value;
        }

return $clone;
    }

    /** @param array<string,mixed> $cookies */
    public function withCookieParams(array $cookies): static
    {
        $clone = clone $this;
        $clone->cookie = self::stringMap($cookies);
        $clone->refreshVariableMap();

        return $clone;
    }

    public function withMethod(string $method): static
    {
        $clone = clone $this;
        $clone->method = HttpMethodEnum::normalize($method);
        $clone->effectiveMethod = null;

        return $clone;
    }

    public function withoutAttribute(string $name): static
    {
        if (!array_key_exists($name, $this->attributes)) {
            return $this;
        } $clone = clone $this;
        unset($clone->attributes[$name]);

        return $clone;
    }

    /** @param array<string,mixed>|object|null $data */
    public function withParsedBody(object|array|null $data): static
    {
        $clone = clone $this;
        $clone->parsed = $data;
        $clone->effectiveMethod = null;
        $clone->refreshVariableMap();

        return $clone;
    }

    /** @param array<string,mixed> $query */
    public function withQueryParams(array $query): static
    {
        $clone = clone $this;
        $clone->query = self::stringMap($query);
        $clone->refreshVariableMap();

        return $clone;
    }

    public function withRequestTarget(string $requestTarget): static
    {
        if (preg_match('#\s#', $requestTarget) === 1) {
            throw new InvalidArgumentException('Whitespace in request-target');
        } $clone = clone $this;
        $clone->requestTarget = $requestTarget;

        return $clone;
    }

    /** @param array<string,mixed> $uploadedFiles */
    public function withUploadedFiles(array $uploadedFiles): static
    {
        $clone = clone $this;
        $clone->filesSpec = self::stringMap($uploadedFiles);
        $clone->filesHydrated = null;
        $clone->filesCollection = null;

        return $clone;
    }

    public function withUri(Uri $uri, bool $preserveHost = false): static
    {
        $clone = clone $this;
        $clone->uri = $uri;
        if (!$preserveHost) {
            $clone->headers['Host'] = $uri->getHost() === '' ? [] : [$uri->getHost() . ($uri->getPort() !== null ? ':' . $uri->getPort() : '')];
        } elseif ($uri->getHost() !== '' && !$clone->hasHeader('Host')) {
            $clone->headers['Host'] = [$uri->getHost()];
        }

return $clone;
    }

    /** @param array<string,mixed> $server */
    private static function serverString(array $server, string $key, string $default = ''): string
    {
        $value = $server[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /** @param array<mixed> $value @return array<string,mixed> */
    private static function stringMap(array $value): array
    {
        $map = [];
        foreach ($value as $key => $entry) {
            if (is_string($key)) {
                $map[$key] = $entry;
            }
        }

return $map;
    }

    private function buildVariableMap(): void
    {
        if ($this->variableMap !== null) {
            return;
        } $order = $this->determineVariableOrder();
        $sources = ['G' => $this->query,'P' => is_array($this->parsed) ? self::stringMap($this->parsed) : [],'C' => $this->cookie,'S' => $this->server];
        $map = [];
        foreach ($order as $source) {
            if ($source === 'E') {
                $map += self::stringMap($_ENV);

                continue;
            } if (isset($sources[$source])) {
                $map += $sources[$source];
            }
        } $this->variableMap = $map;
        $this->checkEnv = in_array('E', $order, true);
    }

    /** @return list<string> */
    private function determineVariableOrder(): array
    {
        $variables = strtoupper((string) preg_replace('/[^EGPCS]/', '', ini_get('variables_order') ?: 'EGPCS'));
        $request = strtoupper((string) preg_replace('/[^GPC]/', '', ini_get('request_order') ?: ''));
        $order = str_split($variables);
        if ($request === '') {
            return $order;
        } $order = array_values(array_diff($order, ['G','P','C']));
        $anchor = array_search('E', $order, true);
        $insert = $anchor === false ? 0 : $anchor + 1;
        foreach (array_reverse(str_split($request)) as $source) {
            array_splice($order, $insert, 0, $source);
        }

return $order;
    }

    private function isFormPost(): bool
    {
        return $this->method === HttpMethodEnum::POST->value && HttpUtils::isFormContentType($this->getHeaderLine('Content-Type'));
    }

    private function methodOverride(): ?string
    {
        $header = $this->getHeaderLine('X-HTTP-Method-Override') ?: $this->getHeaderLine('HTTP-Method-Override');
        if ($header !== '') {
            $candidate = HttpMethodEnum::normalize($header);

            return in_array($candidate, self::VALID, true) ? $candidate : null;
        } if (self::$methodParamOverride && $this->isFormPost()) {
            $parsed = $this->parsed;
            $override = is_array($parsed) ? ($parsed['_method'] ?? null) : null;
            if (is_string($override)) {
                $candidate = HttpMethodEnum::normalize($override);

                return in_array($candidate, self::VALID, true) ? $candidate : null;
            }
        }

return null;
    }

    private function refreshVariableMap(): void
    {
        $this->variableMap = null;
        $this->checkEnv = false;
    }
}

/** @param array<string,mixed>|null $query @return array<string,mixed> */
function server_request_query_parameters(?array $query, Uri $uri): array
{
    if ($query !== null) {
        return $query;
    } if ($uri->getQuery() === '') {
        return server_request_string_map($_GET);
    } parse_str($uri->getQuery(), $uriQuery);

    return server_request_string_map($uriQuery);
}
/** @param array<mixed> $value @return array<string,mixed> */
function server_request_string_map(array $value): array
{
    $map = [];
    foreach ($value as $key => $entry) {
        if (is_string($key)) {
            $map[$key] = $entry;
        }
    }

return $map;
}
