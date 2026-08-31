<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Infocyph\Webrick\Interfaces\BodyStream;
use Infocyph\Webrick\Interop\Psr7\PsrBodyStreamAdapter;
use Psr\Http\Message\ServerRequestInterface;

/** PSR-7 server-request extraction used only at interop runtimes. */
final readonly class PsrServerRequestData
{
    public static function body(ServerRequestInterface $request): BodyStream
    {
        return new PsrBodyStreamAdapter($request->getBody());
    }

    /**
     * @return array<string,list<string>>
     */
    public static function headers(ServerRequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = array_values($values);
        }

        return $headers;
    }

    /**
     * @return array<string,mixed>
     */
    public static function server(ServerRequestInterface $request): array
    {
        $server = self::stringMap($request->getServerParams());
        $uri = $request->getUri();
        $path = $uri->getPath();
        $query = $uri->getQuery();
        $requestUri = ($path === '' ? '/' : $path) . ($query === '' ? '' : '?' . $query);

        $server['REQUEST_METHOD'] = $request->getMethod();
        $server['REQUEST_URI'] = $requestUri;
        $server['REQUEST_SCHEME'] = $uri->getScheme();
        $server['SERVER_PROTOCOL'] = 'HTTP/' . $request->getProtocolVersion();
        $host = $uri->getHost();
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

    /**
     * @return array<string,mixed>
     */
    public static function stringMapResult(mixed $value): array
    {
        return is_array($value) ? self::stringMap($value) : [];
    }

    /**
     * @param array<string,mixed> $server
     */
    private static function copyHeader(array &$server, ServerRequestInterface $request, string $name, string $target): void
    {
        $value = $request->getHeaderLine($name);
        if ($value !== '') {
            $server[$target] = $value;
        }
    }

    /**
     * @param array<array-key,mixed> $value
     * @return array<string,mixed>
     */
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
