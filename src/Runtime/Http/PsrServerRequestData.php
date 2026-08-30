<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Interop\Psr7\PsrBodyStreamAdapter;
use RuntimeException;

/** Duck-typed PSR-7 server-request extraction used only at interop runtimes. */
final readonly class PsrServerRequestData
{
    public static function body(object $request): BodyStream
    {
        $stream = $request->getBody();
        if (!is_object($stream)) {
            throw new RuntimeException('PSR request body must be a stream object.');
        }

        return new PsrBodyStreamAdapter($stream);
    }

    /** @return array<string,string|list<string>> */
    public static function headers(object $request): array
    {
        $headers = $request->getHeaders();
        if (!is_array($headers)) {
            return [];
        }

        $out = [];
        foreach ($headers as $name => $values) {
            if (!is_string($name) || !is_array($values)) {
                continue;
            }
            $list = [];
            foreach ($values as $value) {
                if (is_string($value)) {
                    $list[] = $value;
                }
            }
            $out[$name] = $list;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public static function server(object $request): array
    {
        $params = $request->getServerParams();
        $server = is_array($params) ? self::stringMap($params) : [];
        $uri = $request->getUri();
        $path = (string) $uri->getPath();
        $query = (string) $uri->getQuery();
        $requestUri = ($path === '' ? '/' : $path) . ($query === '' ? '' : '?' . $query);

        $server['REQUEST_METHOD'] = (string) $request->getMethod();
        $server['REQUEST_URI'] = $requestUri;
        $server['REQUEST_SCHEME'] = (string) $uri->getScheme();
        $host = (string) $uri->getHost();
        $port = $uri->getPort();
        if ($host !== '') {
            $server['HTTP_HOST'] = $host . ($port !== null ? ':' . $port : '');
        }

        self::copyHeader($server, $request, 'Content-Type', 'CONTENT_TYPE');
        self::copyHeader($server, $request, 'X-HTTP-Method-Override', 'HTTP_X_HTTP_METHOD_OVERRIDE');
        self::copyHeader($server, $request, 'HTTP-Method-Override', 'HTTP_HTTP_METHOD_OVERRIDE');
        self::copyHeader($server, $request, 'Forwarded', 'HTTP_FORWARDED');
        self::copyHeader($server, $request, 'X-Forwarded-For', 'HTTP_X_FORWARDED_FOR');
        self::copyHeader($server, $request, 'X-Forwarded-Host', 'HTTP_X_FORWARDED_HOST');
        self::copyHeader($server, $request, 'X-Forwarded-Port', 'HTTP_X_FORWARDED_PORT');
        self::copyHeader($server, $request, 'X-Forwarded-Proto', 'HTTP_X_FORWARDED_PROTO');

        return $server;
    }

    /** @return array<string,mixed> */
    public static function stringMapResult(mixed $value): array
    {
        return is_array($value) ? self::stringMap($value) : [];
    }

    /** @param array<string,mixed> $server */
    private static function copyHeader(array &$server, object $request, string $name, string $target): void
    {
        $value = $request->getHeaderLine($name);
        if (is_string($value) && $value !== '') {
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
}
