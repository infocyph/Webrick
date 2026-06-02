<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Url;

use DateTimeInterface;
use Infocyph\Webrick\Interfaces\RouteInterface;
use Infocyph\Webrick\Router\Route\Collection;
use InvalidArgumentException;
use LogicException;

/**
 * Generates URLs for named routes, controller actions, and arbitrary paths.
 *
 * @phpstan-type RouteParam bool|float|int|string|null
 * @phpstan-type QueryValue array<int|string,mixed>|bool|float|int|string|null
 */
class UrlGenerator
{
    public const string EXPIRES_PARAM = SignedUrlConfig::DEFAULT_EXPIRY_PARAM;

    public const string SIG_PARAM = SignedUrlConfig::DEFAULT_SIGNATURE_PARAM;

    private readonly string $basePass;

    private readonly ?int $basePort;

    private readonly string $baseScheme;

    private readonly string $baseUri;

    private readonly string $baseUser;

    private readonly ?SignedUrlConfig $signedConfig;

    /**
     * @param string $baseUri Base URI used for absolute URL generation.
     */
    public function __construct(
        string $baseUri,
        private readonly Collection $routes,
        ?string $secret = null,
        ?int $defaultTtl = null,
        ?SignedUrlConfig $signedConfig = null,
    ) {
        $parts = \parse_url($baseUri);
        if ($parts === false || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('baseUri must not contain query or fragment components');
        }

        $this->baseUri = \rtrim($baseUri, '/');
        $this->baseScheme = \strtolower((string) ($parts['scheme'] ?? ''));
        $this->baseUser = (string) ($parts['user'] ?? '');
        $this->basePass = (string) ($parts['pass'] ?? '');
        $this->basePort = $parts['port'] ?? null;
        $this->signedConfig = SignedUrlConfig::mergeLegacy($signedConfig, $secret, $defaultTtl);
    }

    public static function checkSignature(
        string $payload,
        string $sig,
        string $key,
        string $algorithm = SignedUrlConfig::DEFAULT_ALGORITHM,
    ): bool {
        return \hash_equals(self::makeSignature($payload, $key, $algorithm), $sig);
    }

    public static function makeSignature(
        string $payload,
        string $key,
        string $algorithm = SignedUrlConfig::DEFAULT_ALGORITHM,
    ): string {
        return \hash_hmac($algorithm, $payload, $key);
    }

    /**
     * @param array<string,RouteParam> $params
     * @param array<string,QueryValue> $query
     */
    public function action(
        callable|string $handler,
        array $params = [],
        array $query = [],
        bool $absolute = false,
    ): string {
        $route = $this->routes->findByHandler($handler);
        if ($route === null) {
            throw new InvalidArgumentException('Route for given handler not found.');
        }

        $path = $this->substitute($route->getPath(), $params);

        return $this->buildResolvedPath($path, $query, $absolute, $route->getDomain());
    }

    public function getBaseUri(): string
    {
        return $this->baseUri;
    }

    public function getSignedConfig(): ?SignedUrlConfig
    {
        return $this->signedConfig;
    }

    /**
     * @param array<string,RouteParam> $params
     * @param array<string,QueryValue> $query
     */
    public function signed(
        string $name,
        array $params = [],
        array $query = [],
        ?int $ttl = null,
        bool $absolute = true,
        ?string $payloadMode = null,
    ): string {
        $route = $this->requireNamedRoute($name);
        $expiresAt = $ttl === null ? null : \time() + $this->normalizePositiveInt($ttl, 'ttl');

        return $this->signResolvedPath(
            path: $this->substitute($route->getPath(), $params),
            query: $query,
            expiresAt: $expiresAt,
            absolute: $absolute,
            payloadMode: $payloadMode,
            routeDomain: $route->getDomain(),
        );
    }

    /**
     * @param array<string,RouteParam> $params
     * @param array<string,QueryValue> $query
     */
    public function temporary(
        string $name,
        array $params = [],
        array $query = [],
        ?int $ttl = null,
        bool $absolute = true,
        ?string $payloadMode = null,
    ): string {
        return $this->signed(
            name: $name,
            params: $params,
            query: $query,
            ttl: $ttl ?? $this->requireDefaultTtl(),
            absolute: $absolute,
            payloadMode: $payloadMode,
        );
    }

    /**
     * @param array<string,RouteParam> $params
     * @param array<string,QueryValue> $query
     */
    public function temporaryUntil(
        string $name,
        DateTimeInterface|int $expiresAt,
        array $params = [],
        array $query = [],
        bool $absolute = true,
        ?string $payloadMode = null,
    ): string {
        $route = $this->requireNamedRoute($name);

        return $this->signResolvedPath(
            path: $this->substitute($route->getPath(), $params),
            query: $query,
            expiresAt: $this->normalizeExpiryTimestamp($expiresAt),
            absolute: $absolute,
            payloadMode: $payloadMode,
            routeDomain: $route->getDomain(),
        );
    }

    /**
     * @param array<string,QueryValue> $query
     */
    public function to(string $path, array $query = [], bool $absolute = false): string
    {
        return $this->buildResolvedPath($path, $query, $absolute);
    }

    /**
     * @param array<string,RouteParam> $params
     * @param array<string,QueryValue> $query
     */
    public function urlFor(
        string $name,
        array $params = [],
        array $query = [],
        bool $absolute = false,
    ): string {
        $route = $this->requireNamedRoute($name);
        $path = $this->substitute($route->getPath(), $params);

        return $this->buildResolvedPath($path, $query, $absolute, $route->getDomain());
    }

    /**
     * @param array<string,QueryValue> $query
     */
    private function appendQueryString(string $uri, array $query): string
    {
        if ($query === []) {
            return $uri;
        }

        return $uri . '?' . \http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
    }

    private function assertNoQueryOrFragment(string $value, string $field): void
    {
        $parts = \parse_url($value);
        if ($parts === false) {
            throw new InvalidArgumentException("{$field} must be a valid path or URI.");
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException("{$field} must not contain query or fragment components.");
        }
    }

    /**
     * @param array<string,QueryValue> $query
     */
    private function assertQueryHasNoReservedParameters(array $query, SignedUrlConfig $config): void
    {
        if (
            \array_key_exists($config->signatureParam, $query)
            || \array_key_exists($config->expiryParam, $query)
        ) {
            throw new InvalidArgumentException(
                "Query may not contain reserved parameters '"
                . $config->signatureParam
                . "' or '"
                . $config->expiryParam
                . "'.",
            );
        }
    }

    private function buildAbsolutePayloadTarget(string $path, ?string $routeDomain): string
    {
        $target = $this->isAbsoluteUri($path)
            ? $path
            : $this->buildAbsoluteUri($path, $routeDomain);

        if (!$this->isConcreteAbsoluteUri($target)) {
            throw new LogicException('Absolute signed URLs require a concrete absolute base URI or route domain.');
        }

        return $target;
    }

    private function buildAbsoluteUri(string $path, ?string $routeDomain = null): string
    {
        if ($this->isAbsoluteUri($path) || \str_starts_with($path, '//')) {
            return $path;
        }

        $normalizedPath = $this->normalizePath($path);

        if ($routeDomain === null || $routeDomain === '' || $routeDomain === '*') {
            return ($this->baseUri !== '' ? $this->baseUri : '') . $normalizedPath;
        }

        if ($this->isAbsoluteUri($routeDomain) || \str_starts_with($routeDomain, '//')) {
            return \rtrim($routeDomain, '/') . $normalizedPath;
        }

        if ($this->baseScheme === '') {
            return '//' . $routeDomain . $normalizedPath;
        }

        return $this->baseScheme
            . '://'
            . $this->formatUserInfo()
            . $routeDomain
            . $this->formatPort()
            . $normalizedPath;
    }

    /**
     * @param array<string,QueryValue> $query
     */
    private function buildResolvedPath(
        string $path,
        array $query,
        bool $absolute,
        ?string $routeDomain = null,
    ): string {
        $target = $absolute
            ? $this->buildAbsoluteUri($path, $routeDomain)
            : $this->normalizePath($path);

        return $this->appendQueryString($target, $query);
    }

    /**
     * @param array<string,QueryValue> $query
     */
    private function buildSignaturePayload(
        string $path,
        array $query,
        string $payloadMode,
        ?string $routeDomain,
    ): string {
        $target = $payloadMode === SignedUrlConfig::MODE_ABSOLUTE
            ? $this->buildAbsolutePayloadTarget($path, $routeDomain)
            : $this->extractRelativePath($path);

        return $this->appendQueryString($target, $query);
    }

    /**
     * @param array<string,QueryValue> $query
     * @return array<string,QueryValue>
     */
    private function canonicalizeQuery(array $query): array
    {
        \ksort($query);

        return $query;
    }

    private function extractRelativePath(string $path): string
    {
        if (!$this->isAbsoluteUri($path) && !\str_starts_with($path, '//')) {
            return $this->normalizePath($path);
        }

        $parts = \parse_url($path);
        if ($parts === false) {
            throw new InvalidArgumentException('Signed URL path must be a valid URI or path.');
        }

        $relativePath = (string) ($parts['path'] ?? '/');
        if ($relativePath === '' || $relativePath[0] !== '/') {
            $relativePath = '/' . \ltrim($relativePath, '/');
        }

        return $relativePath;
    }

    private function formatPort(): string
    {
        return $this->basePort === null ? '' : ':' . $this->basePort;
    }

    private function formatUserInfo(): string
    {
        if ($this->baseUser === '') {
            return '';
        }

        return $this->baseUser . ($this->basePass !== '' ? ':' . $this->basePass : '') . '@';
    }

    private function isAbsoluteUri(string $value): bool
    {
        return \preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:\/\//', $value) === 1;
    }

    private function isConcreteAbsoluteUri(string $value): bool
    {
        $parts = \parse_url($value);
        if ($parts === false) {
            return false;
        }

        return \is_string($parts['scheme'] ?? null)
            && $parts['scheme'] !== ''
            && \is_string($parts['host'] ?? null)
            && $parts['host'] !== '';
    }

    private function normalizeExpiryTimestamp(DateTimeInterface|int $expiresAt): int
    {
        if ($expiresAt instanceof DateTimeInterface) {
            $expiresAt = $expiresAt->getTimestamp();
        }

        return $this->normalizePositiveInt($expiresAt, 'expiresAt');
    }

    private function normalizePath(string $path): string
    {
        if ($this->routes->hasPath($path)) {
            return $path;
        }

        return '/' . \ltrim($path, '/');
    }

    private function normalizePositiveInt(int $value, string $field): int
    {
        if ($value < 1) {
            throw new InvalidArgumentException("{$field} must be a positive integer.");
        }

        return $value;
    }

    private function requireDefaultTtl(): int
    {
        $config = $this->requireSignedConfig();
        if ($config->defaultTtl === null) {
            throw new LogicException('Temporary URL generation requires a default TTL.');
        }

        return $config->defaultTtl;
    }

    private function requireGenerationKey(): string
    {
        $config = $this->requireSignedConfig();
        if ($config->generationKey === null) {
            throw new LogicException('Signed URL generation requires a configured key.');
        }

        return $config->generationKey;
    }

    private function requireNamedRoute(string $name): RouteInterface
    {
        if ($name === '') {
            throw new InvalidArgumentException('Route name must not be empty.');
        }

        $route = $this->routes->findByName($name);
        if ($route === null) {
            throw new InvalidArgumentException("Route '{$name}' not found.");
        }

        return $route;
    }

    private function requireSignedConfig(): SignedUrlConfig
    {
        return $this->signedConfig
            ?? throw new LogicException('Signed URL generation requires a configured signing profile.');
    }

    private function resolvePayloadMode(?string $payloadMode, SignedUrlConfig $config): string
    {
        if ($payloadMode === null) {
            return $config->payloadMode;
        }

        return new SignedUrlConfig(payloadMode: $payloadMode)->payloadMode;
    }

    /**
     * @param array<string,QueryValue> $query
     */
    private function signResolvedPath(
        string $path,
        array $query,
        ?int $expiresAt,
        bool $absolute,
        ?string $payloadMode,
        ?string $routeDomain = null,
    ): string {
        $config = $this->requireSignedConfig();
        $this->assertNoQueryOrFragment($path, 'path');
        $this->assertQueryHasNoReservedParameters($query, $config);

        $signedQuery = $this->canonicalizeQuery($query);
        if ($expiresAt !== null) {
            $signedQuery[$config->expiryParam] = $expiresAt;
            $signedQuery = $this->canonicalizeQuery($signedQuery);
        }

        $resolvedPayloadMode = $this->resolvePayloadMode($payloadMode, $config);
        $signedQuery[$config->signatureParam] = self::makeSignature(
            $this->buildSignaturePayload($path, $signedQuery, $resolvedPayloadMode, $routeDomain),
            $this->requireGenerationKey(),
            $config->algorithm,
        );

        return $this->buildResolvedPath($path, $signedQuery, $absolute, $routeDomain);
    }

    /**
     * Replaces placeholders in the URL template with encoded parameter values.
     *
     * @param string $template URL template with {param} or {param:type} placeholders
     * @param array<string,mixed> $params Parameter values
     */
    private function substitute(string $template, array $params): string
    {
        if (!\str_contains($template, '{')) {
            return $template;
        }

        $result = (string) \preg_replace_callback(
            '/\{([A-Za-z_]\w*)(?::[^}]+)?}/',
            function (array $matches) use ($template, $params): string {
                $key = $matches[1];
                if (!\array_key_exists($key, $params)) {
                    throw new InvalidArgumentException(
                        "Missing parameter '{$key}' for URL template '{$template}'.",
                    );
                }

                $value = $params[$key];
                if (!\is_scalar($value) && $value !== null) {
                    throw new InvalidArgumentException(
                        "Parameter '{$key}' must be scalar or null; got " . \gettype($value),
                    );
                }

                return \rawurlencode((string) $value);
            },
            $template,
        );

        if (\preg_match('/\{[A-Za-z_]\w*(?::[^}]+)?}/', $result) === 1) {
            throw new InvalidArgumentException("Unable to resolve all placeholders in '{$template}'.");
        }

        return $result;
    }
}
