<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

final readonly class SwooleNativeRequest
{
    /** @return array<string,mixed> */
    public static function arrayProperty(object $source, string $property): array
    {
        $value = $source->{$property} ?? null;

        return is_array($value) ? self::stringMap($value) : [];
    }

    /** @return array<string,string|list<string>> */
    public static function headers(object $request): array
    {
        $headers = self::arrayProperty($request, 'header');
        $out = [];
        foreach ($headers as $name => $value) {
            if (is_string($value)) {
                $out[$name] = $value;
            } elseif (is_array($value)) {
                $values = [];
                foreach ($value as $item) {
                    if (is_string($item)) {
                        $values[] = $item;
                    }
                }
                $out[$name] = $values;
            }
        }

        return $out;
    }

    public static function isHttp2(object $request): bool
    {
        $server = self::arrayProperty($request, 'server');
        $protocol = $server['server_protocol'] ?? null;

        return is_string($protocol) && str_starts_with(strtoupper($protocol), 'HTTP/2');
    }

    public static function rawBody(object $request): string
    {
        $body = $request->rawContent();

        return is_string($body) ? $body : '';
    }

    /** @return array<string,mixed> */
    public static function server(object $request): array
    {
        $native = self::arrayProperty($request, 'server');
        $headers = self::arrayProperty($request, 'header');
        $requestUri = self::stringValue($native, 'request_uri', '/');
        $query = self::stringValue($native, 'query_string');
        if ($query !== '' && !str_contains($requestUri, '?')) {
            $requestUri .= '?' . $query;
        }

        $server = [
            'REQUEST_METHOD' => self::stringValue($native, 'request_method', 'GET'),
            'REQUEST_URI' => $requestUri,
            'SERVER_PROTOCOL' => self::stringValue($native, 'server_protocol', 'HTTP/1.1'),
            'REMOTE_ADDR' => self::stringValue($native, 'remote_addr'),
            'SERVER_PORT' => self::stringValue($native, 'server_port'),
            'SERVER_NAME' => self::stringValue($native, 'server_name'),
            'REQUEST_SCHEME' => self::stringValue($native, 'request_scheme'),
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

    /** @param array<string,mixed> $server @param array<string,mixed> $headers */
    private static function copyHeader(array &$server, array $headers, string $source, string $target): void
    {
        $value = $headers[$source] ?? null;
        if (is_string($value)) {
            $server[$target] = $value;
        }
    }

    /** @param array<mixed> $value @return array<string,mixed> */
    private static function stringMap(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $source */
    private static function stringValue(array $source, string $key, string $default = ''): string
    {
        $value = $source[$key] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : $default;
    }
}
