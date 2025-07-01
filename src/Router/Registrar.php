<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use Closure;

/**
 * Lightweight *builder* that powers the fluent/attribute-style API.
 *
 *  ▸ Each group() call spawns a **new Registrar** with merged context
 *    (prefix, name-prefix, middleware, domain).
 *  ▸ All verb helpers forward to the parent Router → single source of truth.
 *
 * @internal  End-users never reference this directly; the static Route
 *            facade (planned) or the Router instance is exposed instead.
 */
final class Registrar
{
    /* -------------------------------------------------------------
       Context inherited by child groups
       ------------------------------------------------------------ */
    public function __construct(
        private Router $router,
        private string $prefix        = '',
        private array  $middlewares   = [],
        private ?string $domain       = null,
        private ?string $namePrefix   = null,
    ) {
    }

    /* -------------------------------------------------------------
       Group nesting – merges settings immutably
       ------------------------------------------------------------ */
    public function group(array $opts, Closure $fn): void
    {
        $child = new self(
            $this->router,
            prefix      : rtrim($this->prefix . '/' . ($opts['prefix'] ?? ''), '/'),
            middlewares : array_values(array_unique([
                ...$this->middlewares,
                ...($opts['middleware']   ?? []),
            ], SORT_STRING)),
            domain      : $opts['domain']     ?? $this->domain,
            namePrefix  : rtrim(
                ($this->namePrefix ? $this->namePrefix . '.' : '') .
                ($opts['as'] ?? $opts['name'] ?? ''),
                '.'
            ),
        );

        $fn($child);
    }

    /* -------------------------------------------------------------
       Convenience verb helpers
       ------------------------------------------------------------ */
    public function get(string $path, callable $h): Route
    {
        return $this->add('GET', $path, $h);
    }

    public function post(string $path, callable $h): Route
    {
        return $this->add('POST', $path, $h);
    }

    public function put(string $path, callable $h): Route
    {
        return $this->add('PUT', $path, $h);
    }

    public function patch(string $path, callable $h): Route
    {
        return $this->add('PATCH', $path, $h);
    }

    public function delete(string $path, callable $h): Route
    {
        return $this->add('DELETE', $path, $h);
    }

    public function head(string $path, callable $h): Route
    {
        return $this->add('HEAD', $path, $h);
    }

    public function options(string $path, callable $h): Route
    {
        return $this->add('OPTIONS', $path, $h);
    }

    /* -------------------------------------------------------------
       Internal delegate
       ------------------------------------------------------------ */
    private function add(string $m, string $path, callable $h): Route
    {
        $route = new Route(
            $m,
            '/' . ltrim($this->prefix . '/' . ltrim($path, '/'), '/'),
            $h,
            $this->domain,
            $this->namePrefix,
            $this->middlewares
        );

        return $this->router->register($route);
    }
}
