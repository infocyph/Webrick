<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Contracts\RouteInterface;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Url\UrlGenerator;

require __DIR__ . '/vendor/autoload.php';

/**
 * Minimal RouteInterface stub that ONLY returns real PHP callables.
 */
final class DummyRoute implements RouteInterface
{
    private ?string $name;
    private string $path;
    private string $method;
    private ?string $domain;
    /** @var array<int,callable|string> */
    private array $middlewares;
    private \Closure $handler;

    public function __construct(
        string $name,
        string $path,
        \Closure $handler,
        string $method = 'GET',
        ?string $domain = null,
        array $middlewares = []
    ) {
        $this->name = $name;
        $this->path = $path;
        $this->handler = $handler;
        $this->method = $method;
        $this->domain = $domain;
        $this->middlewares = $middlewares;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHandler(): callable
    {
        // We know it's a \Closure, which is a callable
        return $this->handler;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    public function withName(string $name): RouteInterface
    {
        $clone = clone $this;
        $clone->name = $name;
        return $clone;
    }

    public function withDomain(string|null $domain): RouteInterface
    {
        $clone = clone $this;
        $clone->domain = $domain;
        return $clone;
    }

    public function withMiddleware(callable|string|array $middleware): RouteInterface
    {
        $clone = clone $this;
        $clone->middlewares[] = $middleware;
        return $clone;
    }
}


// 1. Create your routes collection
$routes = new Collection();

// 2. Add a route with a closure handler
$showUser = fn(array $params=[]) => null;  // dummy
$routes->add(new DummyRoute(
    name:    'user.show',
    path:    '/users/{id}',
    handler: $showUser
));

// 3. Add another route
$previewPost = fn(array $params=[]) => null;
$routes->add(new DummyRoute(
    name:    'post.preview',
    path:    '/posts/{slug:slug}',
    handler: $previewPost
));

// 4. Instantiate UrlGenerator
$gen = new UrlGenerator('https://api.example.com', $routes);

// ————————————————————————————————————————————————————————————————
// A) urlFor()
echo $gen->urlFor('user.show', ['id' => 42], ['utm' => 'test']);
// → https://api.example.com/users/42?utm=test

echo "\n";

// relative
echo $gen->urlFor('user.show', ['id'=>99], [], false);
// → /users/99

echo "\n\n";

// ————————————————————————————————————————————————————————————————
// B) to()
echo $gen->to('/health');
// → https://api.example.com/health

echo "\n";

echo $gen->to('search', ['q'=>'php 8.4','page'=>2]);
// → https://api.example.com/search?q=php%208.4&page=2

echo "\n\n";

// ————————————————————————————————————————————————————————————————
// C) action()
echo $gen->action($showUser, ['id'=>7]);
// → https://api.example.com/users/7

echo "\n\n";

// D) missing param
try {
    echo $gen->urlFor('post.preview', []);
} catch (InvalidArgumentException $e) {
    echo "Error: " . $e->getMessage();
// → Error: Missing parameter 'slug' for URL template '/posts/{slug:slug}'.
}

echo "\n\n";

// E) unknown route
try {
    echo $gen->urlFor('no.such');
} catch (InvalidArgumentException $e) {
    echo "Error: " . $e->getMessage();
// → Error: Route 'no.such' not found.
}

echo "\n";
