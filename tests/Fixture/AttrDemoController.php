<?php

namespace Infocyph\Webrick\Tests\Fixture;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\{Group, Produces, Route};

#[Group(prefix: '/attr', name: 'attr.')]
final class AttrDemoController
{
    #[Route(method: 'GET', path: '/hello/{name}', name: 'hello')]
    #[Produces(types: ['application/json'])]
    public function hello(Request $r, string $name)
    {
        return Response::json(['hello' => $name]);
    }
}
