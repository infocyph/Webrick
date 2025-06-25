<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use Infocyph\Webrick\Router\Compiler\RouteDumper;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Infocyph\Webrick\Http\Response;
use Infocyph\Webrick\Http\Stream;
use Infocyph\Webrick\Interfaces\RouterInterface;

final class Router implements RouterInterface
{
    /* -------- concrete factory helpers -------- */

    public static function bootFast(): self
    {
        $d = new RouteDumper();
        $routes = $d->load()
            ?? throw new \RuntimeException('Route cache missing. Run "php webrick route:cache".');
        return new self($routes, $d, false);
    }

    public static function bootDev(): self
    {
        $d = new RouteDumper();
        return new self($d->load() ?? new RouteCollection(), $d, true);
    }

    /* -------- instance -------- */

    private bool  $allowRebuild;
    private bool  $dirty = false;
    private array $ctx   = [['prefix' => '', 'domain' => null, 'mw' => []]];

    private function __construct(
        private readonly RouteCollection $routes,
        private readonly RouteDumper $dumper,
        bool $allowRebuild
    ) {
        $this->allowRebuild = $allowRebuild;
    }

    public function __destruct()
    {
        if ($this->dirty && $this->allowRebuild) {
            $this->dumper->warm($this->routes);
        }
    }

    /* -------- registration -------- */

    public function addRoute(string $verb, string $path, callable $h): Route
    {
        $c  = end($this->ctx);
        $fp = '/' . ltrim(rtrim($c['prefix'], '/') . '/' . ltrim($path, '/'), '/');

        $r = (new Route($verb, $fp, $h))
            ->withDomain($c['domain'] ?? '')
            ->withMiddleware($c['mw']);

        $this->routes->add($r);
        $this->dirty = true;

        return $r;
    }

    /* verb helpers */
    public function get(string $p, callable $h): Route
    {
        return $this->addRoute('GET', $p, $h);
    }
    public function post(string $p, callable $h): Route
    {
        return $this->addRoute('POST', $p, $h);
    }
    public function put(string $p, callable $h): Route
    {
        return $this->addRoute('PUT', $p, $h);
    }
    public function delete(string $p, callable $h): Route
    {
        return $this->addRoute('DELETE', $p, $h);
    }
    public function patch(string $p, callable $h): Route
    {
        return $this->addRoute('PATCH', $p, $h);
    }
    public function options(string $p, callable $h): Route
    {
        return $this->addRoute('OPTIONS', $p, $h);
    }
    public function head(string $p, callable $h): Route
    {
        return $this->addRoute('HEAD', $p, $h);
    }

    /* -------- grouping / scoping -------- */

    public function group(string $prefix, callable $cb, ?string $domain = null): self
    {
        $c = end($this->ctx);
        $this->ctx[] = [
            'prefix' => '/' . ltrim(rtrim($c['prefix'], '/') . '/' . ltrim($prefix, '/'), '/'),
            'domain' => $domain ?? $c['domain'],
            'mw'     => $c['mw'],
        ];
        $cb($this);
        array_pop($this->ctx);
        return $this;
    }

    public function scope(string $prefix = '', ?string $domain = null, array $mw = []): self
    {
        $clone         = clone $this;
        $base          = end($this->ctx);
        $clone->ctx    = [$base]; // reset stack
        $clone->ctx[]  = [
            'prefix' => '/' . ltrim(rtrim($base['prefix'], '/') . '/' . ltrim($prefix, '/'), '/'),
            'domain' => $domain ?? $base['domain'],
            'mw'     => array_merge($base['mw'], $mw),
        ];
        return $clone;
    }

    /* -------- dispatcher -------- */

    public function handle(ServerRequestInterface $req): ResponseInterface
    {
        [$route, $params] = $this->routes->match($req);
        foreach ($params as $k => $v) {
            $req = $req->withAttribute($k, $v);
        }

        $out = ($route->getHandler())($req);

        if ($out instanceof ResponseInterface) {
            return $out;
        }

        return (new Response())->withBody(new Stream(is_string($out) ? $out : json_encode($out)));
    }

    /* -------- urlFor -------- */

    public function urlFor(string $name, array $params = [], bool $abs = false, array $q = []): string
    {
        $r = $this->routes->named()[$name]
            ?? throw new \RuntimeException("No named route '{$name}'");

        $url = preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)(?::[^}]+)?\}/',
            static function ($m) use (&$params) {
                $k = $m[1];
                if (!array_key_exists($k, $params)) {
                    throw new \RuntimeException("Missing parameter '{$k}'");
                }
                $val = $params[$k];
                unset($params[$k]);
                return $val;
            },
            $r->getPath()
        );

        if ($params) {
            throw new \RuntimeException('Unused parameters: ' . implode(', ', array_keys($params)));
        }
        if ($q) {
            $url .= '?' . http_build_query($q);
        }
        if ($abs && $r->getDomain()) {
            $url = 'https://' . $r->getDomain() . $url;
        }

        return $url;
    }
}
