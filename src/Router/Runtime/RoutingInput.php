<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Runtime;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Request\Core\UriServerParams;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Support\HttpUtils;

/** Lightweight immutable routing preflight that avoids materializing Request. */
final readonly class RoutingInput
{
    /** @var list<string> */
    private const array METHOD_OVERRIDE_TARGETS = [
        HttpMethodEnum::GET->value,
        HttpMethodEnum::POST->value,
        HttpMethodEnum::PUT->value,
        HttpMethodEnum::DELETE->value,
        HttpMethodEnum::PATCH->value,
        HttpMethodEnum::HEAD->value,
        HttpMethodEnum::OPTIONS->value,
        HttpMethodEnum::CONNECT->value,
        HttpMethodEnum::TRACE->value,
    ];

    public function __construct(
        public string $method,
        public string $path,
        public string $host = '*',
    ) {
        if ($method === '' || $path === '' || $host === '') {
            throw new \InvalidArgumentException('Routing input method, path and host must be non-empty.');
        }
    }

    public static function fromGlobals(bool $withHost): self
    {
        /** @var array<string,mixed> $server */
        $server = $_SERVER;
        /** @var array<string,mixed> $form */
        $form = $_POST;

        return self::fromServer($server, $withHost, $form);
    }

    public static function fromRequest(Request $request, bool $withHost): self
    {
        $raw = HttpMethodEnum::normalize($request->getMethod());
        $method = $raw === HttpMethodEnum::HEAD->value
            ? HttpMethodEnum::HEAD->value
            : HttpMethodEnum::normalize($request->getEffectiveMethod());
        $path = $request->getUri()->getPath() ?: '/';
        $host = $withHost ? self::normalizeHost($request->getUri()->getHost()) : '*';

        return new self($method, $path, $host);
    }

    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $form
     */
    public static function fromServer(array $server, bool $withHost, array $form = []): self
    {
        $host = '*';
        if ($withHost) {
            [$rawHost] = UriServerParams::detectHostPort($server);
            $host = self::normalizeHost($rawHost);
        }

        return new self(
            method: self::routingMethodFromServer($server, $form),
            path: self::normalizeRequestPath(UriServerParams::detectRequestUri($server)),
            host: $host,
        );
    }

    /** @param array<string,mixed> $server */
    private static function headerMethodOverride(array $server): ?string
    {
        foreach (['HTTP_X_HTTP_METHOD_OVERRIDE', 'HTTP_HTTP_METHOD_OVERRIDE'] as $key) {
            $value = $server[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $candidate = HttpMethodEnum::normalize($value);

            return in_array($candidate, self::METHOD_OVERRIDE_TARGETS, true) ? $candidate : null;
        }

        return null;
    }

    private static function normalizeHost(string $raw): string
    {
        if ($raw === '' || preg_match('/[\x00-\x20]/', $raw) === 1) {
            throw HttpException::badRequest('Illegal Host header.');
        }
        $host = strtolower(rtrim($raw, '.'));
        if (function_exists('idn_to_ascii') && !str_contains($host, 'xn--')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw HttpException::badRequest('Invalid IDN host name.');
            }
            $host = $ascii;
        }
        if (preg_match('/^[\x21-\x7E]+$/', $host) !== 1) {
            throw HttpException::badRequest('Host contains non-ASCII bytes.');
        }

        return $host;
    }

    private static function normalizeRequestPath(string $requestUri): string
    {
        if ($requestUri === '' || $requestUri === '/') {
            return '/';
        }
        if ($requestUri === '*') {
            return '*';
        }

        if ($requestUri[0] === '/') {
            $length = strcspn($requestUri, '?#');
            $path = $length === strlen($requestUri) ? $requestUri : substr($requestUri, 0, $length);
        } else {
            $parts = parse_url($requestUri);
            if ($parts === false) {
                throw HttpException::badRequest('Invalid request URI.');
            }
            $path = (string) ($parts['path'] ?? '/');
        }

        if ($path === '' || $path === '/') {
            return '/';
        }
        if (!str_contains($path, '//') && !str_contains($path, '/.')) {
            return $path;
        }

        do {
            $previous = $path;
            $path = preg_replace('#(/\.?/)#', '/', $path) ?? $previous;
            $path = preg_replace('#/(?!\.\.)[^/]+/\.\./#', '/', $path) ?? $previous;
            $path = preg_replace('#^/\.\.(?=/|$)#', '/', $path) ?? $previous;
        } while ($path !== $previous);

        return $path;
    }

    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $form
     */
    private static function routingMethodFromServer(array $server, array $form): string
    {
        $value = $server['REQUEST_METHOD'] ?? HttpMethodEnum::GET->value;
        $raw = HttpMethodEnum::normalize(is_string($value) ? $value : HttpMethodEnum::GET->value);
        if ($raw === HttpMethodEnum::HEAD->value) {
            return HttpMethodEnum::HEAD->value;
        }
        if ($raw !== HttpMethodEnum::POST->value) {
            return $raw;
        }

        $headerOverride = self::headerMethodOverride($server);
        if ($headerOverride !== null) {
            return $headerOverride;
        }

        if (!Request::getMethodParamOverride()) {
            return HttpMethodEnum::POST->value;
        }

        $contentType = $server['CONTENT_TYPE'] ?? $server['HTTP_CONTENT_TYPE'] ?? '';
        if (!is_string($contentType) || !HttpUtils::isFormContentType($contentType)) {
            return HttpMethodEnum::POST->value;
        }

        $override = $form['_method'] ?? null;
        if (!is_string($override)) {
            return HttpMethodEnum::POST->value;
        }

        $candidate = HttpMethodEnum::normalize($override);

        return in_array($candidate, self::METHOD_OVERRIDE_TARGETS, true)
            ? $candidate
            : HttpMethodEnum::POST->value;
    }
}
