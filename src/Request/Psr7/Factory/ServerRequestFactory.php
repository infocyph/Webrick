<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Request\Psr7\Factory;

use Infocyph\Webrick\Request\Psr7\ServerRequest;
use Psr\Http\Message\{ServerRequestInterface, ServerRequestFactoryInterface, UriInterface};

final class ServerRequestFactory implements ServerRequestFactoryInterface
{
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        return new ServerRequest($method, $uri instanceof UriInterface ? $uri : (string)$uri, $serverParams);
    }
}
