<?php

declare(strict_types=1);

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use Infocyph\Webrick\Router\Route\Route;

it('assigns deterministic registration-order indexes for collection compilation', function (): void {
    $first = new Collection();
    $first->add(new Route('GET', '/a', static fn(): Response => Response::plaintext('a')));
    $first->add(new Route('GET', '/b', static fn(): Response => Response::plaintext('b')));

    // Advance the direct compatibility counter; collection compilation must not care.
    CompiledRoute::fromRoute(new Route('GET', '/unrelated', static fn(): Response => Response::plaintext('x')));

    $second = new Collection();
    $second->add(new Route('GET', '/a', static fn(): Response => Response::plaintext('a')));
    $second->add(new Route('GET', '/b', static fn(): Response => Response::plaintext('b')));

    expect(array_map(static fn(CompiledRoute $route): int => $route->getIndex(), $first->compile()->all()))
        ->toBe([0, 1])
        ->and(array_map(static fn(CompiledRoute $route): int => $route->getIndex(), $second->compile()->all()))
        ->toBe([0, 1]);
});
