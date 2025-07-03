<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Request\Psr7\Factory;

use Infocyph\Webrick\Request\Core\Uri;
use Psr\Http\Message\{UriFactoryInterface, UriInterface};

final class UriFactory implements UriFactoryInterface
{
    public function createUri(string $uri = ''): UriInterface
    {
        return new Uri($uri);
    }
}
