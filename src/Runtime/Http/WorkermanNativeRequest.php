<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use RuntimeException;

final readonly class WorkermanNativeRequest
{
    /** @return array<string,mixed> */
    public static function cookies(object $request): array
    {
        return self::stringMap(self::call($request, 'cookie'));
    }

    /** @return array<string,mixed> */
    public static function files(object $request): array
    {
        return self::stringMap(self::call($request, 'file'));
    }

    /** @return array<string,string|list<string>> */
    public static function headers(object $request): array
    {
        $headers = self::stringMap(self::call($request, 'header'));
        $out = [];
        foreach ($headers as $name => $value) {
            if (is_string($value)) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    public static function isHttp2(object $request): bool
    {
        $version = self::string(self::call($request, 'protocolVersion'));

        return str_starts_with($version, '2');
    }

    /** @return array<string,mixed> */
    public static function post(object $request): array
    {
        return self::stringMap(self::call($request, 'post'));
    }

    /** @return array<string,mixed> */
    public static function query(object $request): array
    {
        return self::stringMap(self::call($request, 'get'));
    }

    public static function rawBody(object $request): string
    {
        $body = self::call($request, 'rawBody');

        return is_string($body) ? $body : '';
    }

    /** @return array<string,mixed> */
    public static function server(object $request, object $connection): array
    {
        $headers = self::stringMap(self::call($request, 'header'));
        $worker = self::property($connection, 'worker');
        $scheme = (is_object($worker) && self::property($worker, 'transport') === 'ssl') ? 'https' : 'http';
        $server = [
            'REQUEST_METHOD' => self::string(self::call($request, 'method')),
            'REQUEST_URI' => self::string(self::call($request, 'uri')),
            'SERVER_PROTOCOL' => 'HTTP/' . self::string(self::call($request, 'protocolVersion')),
            'REMOTE_ADDR' => self::string(self::call($connection, 'getRemoteIp')),
            'REQUEST_SCHEME' => $scheme,
        ];

        self::copyHeader($server, $headers, 'host', 'HTTP_HOST');
        self::copyHeader($server, $headers, 'content-type', 'CONTENT_TYPE');
        self::copyHeader($server, $headers, 'x-http-method-override', 'HTTP_X_HTTP_METHOD_OVERRIDE');
        self::copyHeader($server, $headers, 'http-method-override', 'HTTP_HTTP_METHOD_OVERRIDE');
        self::copyHeader($server, $headers, 'forwarded', 'HTTP_FORWARDED');
        self::copyHeader($server, $headers, 'x-forwarded-for', 'HTTP_X_FORWARDED_FOR');
        self::copyHeader($server, $headers, 'x-forwarded-host', 'HTTP_X_FORWARDED_HOST');
        self::copyHeader($server, $headers, 'x-forwarded-port', 'HTTP_X_FORWARDED_PORT');
        self::copyHeader($server, $headers, 'x-forwarded-proto', 'HTTP_X_FORWARDED_PROTO');

        return $server;
    }

    private static function call(object $target, string $method): mixed
    {
        if (!method_exists($target, $method)) {
            throw new RuntimeException("Workerman native object does not support {$method}().");
        }

        return $target->{$method}();
    }

    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $headers
     */
    private static function copyHeader(array &$server, array $headers, string $source, string $target): void
    {
        $value = $headers[$source] ?? null;
        if (is_string($value)) {
            $server[$target] = $value;
        }
    }

    private static function property(object $target, string $property): mixed
    {
        return $target->{$property} ?? null;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    /** @return array<string,mixed> */
    private static function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }
}
