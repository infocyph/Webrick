<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Http\Response;
use Infocyph\Webrick\Http\Stream;
use Infocyph\Webrick\Http\Request as HttpRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * High-performance router with pure-container dispatch.
 *  – No PSR-15 types anywhere
 *  – Any handler / middleware parameter is auto-wired by Intermix
 */
final class Router
{
    /* -----------------------------------------------------------------
     * Boot helpers
     * ----------------------------------------------------------------- */
    public static function bootFast(?Container $c = null): self
    {
        $d   = new Compiler\RouteDumper();
        $tab = $d->load()
            ?? throw new \RuntimeException('Route cache missing – run "webrick route:cache"');
        return new self($tab, $d, false, $c ?? Container::instance());
    }

    public static function bootDev(?Container $c = null): self
    {
        $d   = new Compiler\RouteDumper();
        $tab = $d->load() ?? new RouteCollection();
        return new self($tab, $d, true, $c ?? Container::instance());
    }

    /* -----------------------------------------------------------------
     * State
     * ----------------------------------------------------------------- */
    private bool  $allowRebuild;
    private bool  $dirty = false;
    private array $ctx   = [['prefix' => '', 'domain' => null, 'mw' => []]];
    private Invoker $invoker;

    private function __construct(
        private readonly RouteCollection $routes,
        private readonly Compiler\RouteDumper $dumper,
        bool $allowRebuild,
        private readonly Container $container,
    ) {
        $this->allowRebuild = $allowRebuild;
        $this->invoker      = Invoker::with($this->container);
    }

    public function __destruct()
    {
        if ($this->dirty && $this->allowRebuild) {
            $this->dumper->warm($this->routes);
        }
    }

    /* -----------------------------------------------------------------
     * Registration helpers (identical semantics, no PSR-15 types)
     * ----------------------------------------------------------------- */
    public function addRoute(string $verb, string $path, callable|string|array $h): Route
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

    /* verb shortcuts */
    public function get(string $p, callable|string|array $h)
    {
        return $this->addRoute('GET', $p, $h);
    }
    public function post(string $p, callable|string|array $h)
    {
        return $this->addRoute('POST', $p, $h);
    }
    public function put(string $p, callable|string|array $h)
    {
        return $this->addRoute('PUT', $p, $h);
    }
    public function delete(string $p, callable|string|array $h)
    {
        return $this->addRoute('DELETE', $p, $h);
    }
    public function patch(string $p, callable|string|array $h)
    {
        return $this->addRoute('PATCH', $p, $h);
    }
    public function options(string $p, callable|string|array $h)
    {
        return $this->addRoute('OPTIONS', $p, $h);
    }
    public function head(string $p, callable|string|array $h)
    {
        return $this->addRoute('HEAD', $p, $h);
    }

    /* grouping */
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
        $clone        = clone $this;
        $base         = end($this->ctx);
        $clone->ctx   = [$base];
        $clone->ctx[] = [
            'prefix' => '/' . ltrim(rtrim($base['prefix'], '/') . '/' . ltrim($prefix, '/'), '/'),
            'domain' => $domain ?? $base['domain'],
            'mw'     => array_merge($base['mw'], $mw),
        ];
        return $clone;
    }

    /* -----------------------------------------------------------------
     * Dispatch (container pipeline)
     * ----------------------------------------------------------------- */
    public function handle(ServerRequestInterface $req): ResponseInterface
    {
        /** Ensure we pass the richer facade when possible */
        if (!$req instanceof HttpRequest && $req instanceof \Infocyph\Webrick\Http\ServerRequest) {
            $req = new HttpRequest(
                $req->getMethod(),
                $req->getUri(),
                $req->getServerParams(),
                $req->getHeaders(),
                $req->getBody(),
                $req->getProtocolVersion(),
                $req->getParsedBody() ?? [],
                $req->getUploadedFiles(),
                $req->getRequestTarget()
            );
        }

        [$route, $params] = $this->routes->match($req);
        foreach ($params as $k => $v) {
            $req = $req->withAttribute($k, $v);
        }

        /* Bind the live request into the container */
        $this->container->definitions()
            ->bind(ServerRequestInterface::class, $req)
            ->bind(HttpRequest::class, $req);

        $core = fn (ServerRequestInterface $r): ResponseInterface =>
        $this->expectResponse($this->invoker->invoke($route->getHandler()));

        $pipeline = $this->wrapMiddleware($core, $route->getMiddlewares());

        return $pipeline($req);
    }

    /** turn mixed into ResponseInterface or throw */
    private function expectResponse(mixed $out): ResponseInterface
    {
        return $out instanceof ResponseInterface
            ? $out
            : (new Response())->withBody(new Stream(is_scalar($out) ? (string) $out : json_encode($out)));
    }

    /** build reverse middleware stack (callables or objects) */
    private function wrapMiddleware(callable $core, array $mwList): callable
    {
        foreach (array_reverse($mwList) as $entry) {
            $mw = $this->resolveMiddleware($entry);
            $core = fn (ServerRequestInterface $req): ResponseInterface =>
            $this->expectResponse($this->invoker->invoke($mw, ['request' => $req, 'next' => $core]));
        }
        return $core;
    }

    /** via container: callable or [class,method], or object with __invoke/handle */
    private function resolveMiddleware(string|callable|object $def): callable
    {
        if (is_callable($def)) {
            return $def;
        }
        if ($this->container->has($def)) {
            return $this->container->get($def);
        }
        if (class_exists($def)) {
            return $this->container->make($def);
        }
        throw new \RuntimeException("Cannot resolve middleware {$def}");
    }

    /* -----------------------------------------------------------------
     * URL helper (unchanged)
     * ----------------------------------------------------------------- */
    public function urlFor(string $name, array $params = [], bool $abs = false, array $q = []): string
    {
        $r = $this->routes->named()[$name]
            ?? throw new \RuntimeException("No named route {$name}");

        $url = preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)(?::[^}]+)?}/',
            static function ($m) use (&$params) {
                $k = $m[1];
                if (!array_key_exists($k, $params)) {
                    throw new \RuntimeException("Missing parameter {$k}");
                }
                $v = $params[$k];
                unset($params[$k]);
                return $v;
            },
            $r->getPath()
        );

        if ($params) {
            throw new \RuntimeException('Unused params: ' . implode(', ', array_keys($params)));
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
