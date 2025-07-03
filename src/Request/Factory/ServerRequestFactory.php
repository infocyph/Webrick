<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Factory;

use Infocyph\Webrick\Request\{ServerRequest, Uri};
use Psr\Http\Message\{ServerRequestFactoryInterface, UriInterface};

final class ServerRequestFactory implements ServerRequestFactoryInterface
{
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequest
    {
        $uri = $uri instanceof UriInterface ? $uri : Uri::from((string) $uri);
        return new ServerRequest($method, $uri, $serverParams);
    }
}
