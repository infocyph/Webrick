<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Runtime;

use Closure;
use Infocyph\Webrick\Router\Runtime\Route;
use Infocyph\Webrick\Router\Router;

/**
 * Immutable helper that powers the fluent API (`Route::group()` etc.).
 * Users never instantiate this directly – it is built by {@see Router}.
 */
final class Registrar
{
    /* --------------------------------------------------------------------- */
    public function __construct(
        private Router  $router,
        private string  $prefix      = '',
        private array   $middleware  = [],
        private ?string $domain      = null,
        private ?string $namePrefix  = null,
    ) {}

    /* --------------------------------------------------------------------- */
    public function group(array $opts, Closure $fn): void
    {
        $child = new self(
            router     : $this->router,
            prefix     : rtrim($this->prefix . '/' . ($opts['prefix'] ?? ''), '/'),
            middleware : array_values(array_unique([
                ...$this->middleware,
                ...($opts['middleware'] ?? []),
            ], SORT_STRING)),
            domain     : $opts['domain'] ?? $this->domain,
            namePrefix : rtrim(
                ($this->namePrefix ? $this->namePrefix . '.' : '') .
                ($opts['as'] ?? $opts['name'] ?? ''),
                '.'
            ),
        );

        $fn($child);
    }

    /* --------------------------------------------------------------------- */
    public function get(string $p, callable $h): Route   { return $this->add('GET',    $p, $h); }
    public function post(string $p, callable $h): Route  { return $this->add('POST',   $p, $h); }
    public function put(string $p, callable $h): Route   { return $this->add('PUT',    $p, $h); }
    public function patch(string $p, callable $h): Route { return $this->add('PATCH',  $p, $h); }
    public function delete(string $p, callable $h): Route{ return $this->add('DELETE', $p, $h); }
    public function head(string $p, callable $h): Route  { return $this->add('HEAD',   $p, $h); }
    public function options(string $p, callable $h): Route{return $this->add('OPTIONS',$p, $h); }

    /* --------------------------------------------------------------------- */
    private function add(string $verb, string $path, callable $handler): Route
    {
        $route = new Route(
            $verb,
            '/' . ltrim($this->prefix . '/' . ltrim($path, '/'), '/'),
            $handler,
            $this->domain,
            $this->namePrefix,
            $this->middleware,
        );

        return $this->router->register($route);
    }
}
