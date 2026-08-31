<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Core;

/** Construct validated request URIs directly from transport components. */
final class UriComponents
{
    /**
     * @param array<string,mixed> $server
     */
    public static function fromServerParams(array $server, ?int $trustedProxyFlags = null): Uri
    {
        $scheme = UriServerParams::detectScheme($server, $trustedProxyFlags);
        [$host, $port] = UriServerParams::detectHostPort($server, $trustedProxyFlags);
        $requestUri = UriServerParams::detectRequestUri($server);
        $queryPosition = strpos($requestUri, '?');
        $path = $queryPosition === false ? $requestUri : substr($requestUri, 0, $queryPosition);
        $query = $queryPosition === false ? '' : substr($requestUri, $queryPosition + 1);

        return new Uri()
            ->withScheme($scheme)
            ->withHost($host)
            ->withPort($port)
            ->withPath($path === '' ? '/' : $path)
            ->withQuery($query);
    }
}
