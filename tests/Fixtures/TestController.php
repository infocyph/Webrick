<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Tests\Fixture;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\Group;
use Infocyph\Webrick\Router\Definition\Attribute\Route;

#[Group(prefix: '/test')]
class TestController
{
    #[Route(method: 'GET', path: '/hello/{name}', name: 'test.hello')]
    public function hello(string $name): Response
    {
        return Response::json(['message' => "Hello, {$name}!"]);
    }

    #[Route(method: 'POST', path: '/echo', name: 'test.echo')]
    public function echo(Request $req): Response
    {
        return Response::json($req->all());
    }

    #[Route(method: 'GET', path: '/info', name: 'test.info', middleware: [TestMiddleware::class])]
    public function info(): Response
    {
        return Response::json([
            'controller' => self::class,
            'method' => 'info',
        ]);
    }
}
