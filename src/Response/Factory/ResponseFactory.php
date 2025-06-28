<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Factory;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Infocyph\Webrick\Response\Response;

final class ResponseFactory implements ResponseFactoryInterface
{
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return new Response($code, body: null, reasonPhrase: $reasonPhrase);
    }
}
