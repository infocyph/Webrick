<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Request\Psr7\Factory;

use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Psr7\Response;
use Psr\Http\Message\{ResponseInterface, ResponseFactoryInterface};

final class ResponseFactory implements ResponseFactoryInterface
{
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response($code, new Stream(), [], '1.1', $reasonPhrase);
    }
}
