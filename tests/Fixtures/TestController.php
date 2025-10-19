<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Tests\Fixture;

use Infocyph\Webrick\Router\Definition\Attribute\Route;
use Infocyph\Webrick\Router\Definition\Attribute\Get;
use Infocyph\Webrick\Router\Definition\Attribute\Post;
use Infocyph\Webrick\Response\Response;

#[Route('/test')]
class TestController
{
    #[Get('/hello/{name}', name: 'test.hello')]
    public function hello(string $name): Response
    {
        return Response::json(['message' => "Hello, {$name}!"]);
    }

    #[Post('/echo', name: 'test.echo')]
    public function echo($req): Response
    {
        return Response::json($req->all());
    }

    #[Get('/info', name: 'test.info', middleware: ['TestMiddleware'])]
    public function info(): Response
    {
        return Response::json([
            'controller' => self::class,
            'method' => 'info',
        ]);
    }
}
