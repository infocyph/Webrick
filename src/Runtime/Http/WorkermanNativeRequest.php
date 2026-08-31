<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

final readonly class WorkermanNativeRequest
{
    /**
     * @return array<string,mixed>
     * @param object $request
     */
    public static function cookies(object $request): array
    {
        return self::stringMap($request->cookie());
    }

    /**
     * @return array<string,mixed>
     * @param object $request
     */
    public static function files(object $request): array
    {
        return self::stringMap($request->file());
    }

    /**
     * @return array<string,string|list<string>>
     * @param object $request
     */
    public static function headers(object $request): array
    {
        $headers = self::stringMap($request->header());
        $out = [];
        foreach ($headers as $name => $value) {
            if (is_string($value)) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     * @param object $request
     */
    public static function post(object $request): array
    {
        return self::stringMap($request->post());
    }

    /**
     * @return array<string,mixed>
     * @param object $request
     */
    public static function query(object $request): array
    {
        return self::stringMap($request->get());
    }

    public static function rawBody(object $request): string
    {
        $body = $request->rawBody();

        return is_string($body) ? $body : '';
    }

    /**
     * @return array<string,mixed>
     * @param object $request
     * @param object $connection
     */
    public static function server(object $request, object $connection): array
    {
        $headers = self::stringMap($request->header());
        $scheme = (($connection->worker->transport ?? null) === 'ssl') ? 'https' : 'http';
        $server = [
            'REQUEST_METHOD' => (string) $request->method(),
            'REQUEST_URI' => (string) $request->uri(),
            'SERVER_PROTOCOL' => 'HTTP/' . $request->protocolVersion(),
            'REMOTE_ADDR' => (string) $connection->getRemoteIp(),
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

    /**
     * @param array<string,mixed> $server @param array<string,mixed> $headers
     * @param array $server
     * @param string $source
     * @param string $target
     */
    private static function copyHeader(array &$server, array $headers, string $source, string $target): void
    {
        $value = $headers[$source] ?? null;
        if (is_string($value)) {
            $server[$target] = $value;
        }
    }

    /**
     * @return array<string,mixed>
     * @param mixed $value
     */
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
