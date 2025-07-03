<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router_OLD;

use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Invoker;
use Infocyph\Webrick\Exceptions\MethodNotAllowedException;
use Infocyph\Webrick\Exceptions\RouteNotFoundException;
use Infocyph\Webrick\Request_OLD\Request as HttpRequest;
use Infocyph\Webrick\Request_OLD\ServerRequest;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Lean, container-native router.
 *
 *  • static-hash + ordered-dynamic matcher
 *  • middleware at 3 layers: global ▸ scope ▸ route
 *  • alias registry for “route middleware” strings (Laravel-style)
 *  • zero PSR-15 friction – everything is a bare callable resolved by Intermix
 *
 * ───────────────────────────────────────────────────────────────────────────
 * Not addressed (yet)
 * ───────────────────────────────────────────────────────────────────────────
 *  1. Wild-card sub-domain routing
 *  2. “After”/terminable middleware (post-response hooks)
 *  3. CLI command routing kernel
 *  4. Attribute-based controller discovery
 *
 * ───────────────────────────────────────────────────────────────────────────
 * Possible improvements / ideas
 * ───────────────────────────────────────────────────────────────────────────
 *  • Cache resolved middleware instances to cut container look-ups.
 *  • Auto-normalise non-Response returns (arrays/strings ⇒ JSON/text).
 *  • Persist compiled regex list as an op-cached PHP array for prod boots.
 *  • Provide OpenAPI export from the typed-route metadata.
 *  • Add request decorators (e.g. validated DTO injection) via Invoker hooks.
 */
final class Router
{
    private array $middlewareAliases = [];   // <— NEW

    /* -------------------------------------------------
     * register aliases once during boot
     * --------------------------------------------- */
    public function aliasMiddleware(array $map): self
    {
        $this->middlewareAliases = $map + $this->middlewareAliases;
        return $this;
    }

    /* -------------------------------------------------
     * the resolver now understands aliases
     * --------------------------------------------- */
    private function resolve(string|callable|object $def): callable
    {
        /* 1 )  translate string alias → FQCN --------------------- */
        if (\is_string($def) && isset($this->middlewareAliases[$def])) {
            $def = $this->middlewareAliases[$def];
        }

        /* 2 )  already-callable (closure, fn-string, invokable obj) */
        if (\is_callable($def)) {
            return $def;
        }

        /* 3 )  container singletons/services -------------------- */
        if ($this->container->has($def)) {
            return $this->container->get($def);
        }

        /* 4 )  construct fresh class instances ------------------ */
        if (\class_exists($def)) {
            return $this->container->make($def);
        }

        /* 5 )  still nothing? → hard-fail ------------------------ */
        throw new \RuntimeException("Cannot resolve middleware {$def}");
    }
    /* ───── boot helpers ───── */
    public static function bootDev(?Container $c = null): self
    {
        return new self(new RouteCollection(), true, $c ?? Container::instance());
    }
    public static function bootFast(RouteCollection $prebuilt, ?Container $c = null): self
    {
        return new self($prebuilt, false, $c ?? Container::instance());
    }

    /* ───── state ───── */
    private Invoker   $inv;
    private array     $ctx = [['prefix' => '', 'domain' => null, 'mw' => []]];
    private array     $globalMw = [];
    private array     $routeAliases = [];

    private function __construct(
        private readonly RouteCollection $routes,
        private readonly bool            $writeCache,
        private readonly Container       $container,
    ) {
        $this->inv = Invoker::with($this->container);
    }

    public function __destruct()
    {
        if ($this->writeCache && $this->routes->isDirty()) {
            (new Compiler\RouteDumper())->warm($this->routes);
        }
    }

    /* ───── middleware helpers ───── */
    public function globalMiddleware(callable|string|object ...$stack): self
    {
        $this->globalMw = [...$this->globalMw, ...$stack];
        return $this;
    }

    /** Register route-middleware aliases:  ->alias(['auth' => AuthMw::class]) */
    public function alias(array $map): self
    {
        $this->routeAliases += $map;
        return $this;
    }

    /* ───── registration ───── */
    public function addRoute(string $verb, string $path, callable|string|array $h): Route
    {
        $c  = end($this->ctx);
        $fp = '/' . ltrim(rtrim($c['prefix'], '/') . '/' . ltrim($path, '/'), '/');

        // array-style shortcut with ‘uses’ + ‘middleware’
        $mw = $c['mw'];
        if (is_array($h) && isset($h['uses'])) {
            $mw = [...$mw, ...$this->expandAliases($h['middleware'] ?? [])];
            $h  = $h['uses'];
        }

        $route = new Route($verb, $fp, $h);
        $route->withDomain($c['domain'] ?? '')
            ->withMiddleware($mw);

        $this->routes->add($route);
        return $route;
    }

    /* verb helpers */
    public function get(string $p, callable|string|array $h): Route
    {
        return $this->addRoute('GET', $p, $h);
    }
    public function post(string $p, callable|string|array $h): Route
    {
        return $this->addRoute('POST', $p, $h);
    }
    public function put(string $p, callable|string|array $h): Route
    {
        return $this->addRoute('PUT', $p, $h);
    }
    public function patch(string $p, callable|string|array $h): Route
    {
        return $this->addRoute('PATCH', $p, $h);
    }
    public function delete(string $p, callable|string|array $h): Route
    {
        return $this->addRoute('DELETE', $p, $h);
    }
    public function options(string $p, callable|string|array $h): Route
    {
        return $this->addRoute('OPTIONS', $p, $h);
    }
    public function head(string $p, callable|string|array $h): Route
    {
        return $this->addRoute('HEAD', $p, $h);
    }

    /* grouping */
    public function group(string $prefix, callable $fn, ?string $domain = null, array $mw = []): self
    {
        $c = end($this->ctx);
        $this->ctx[] = [
            'prefix' => '/' . ltrim(rtrim($c['prefix'], '/') . '/' . ltrim($prefix, '/'), '/'),
            'domain' => $domain ?? $c['domain'],
            'mw'     => array_merge($c['mw'], $this->expandAliases($mw)),
        ];
        $fn($this);
        array_pop($this->ctx);
        return $this;
    }

    /* scope – returns a new “view” with merged ctx               */
    public function scope(string $prefix = '', ?string $domain = null, array $mw = []): self
    {
        $clone        = clone $this;
        $base         = end($this->ctx);
        $clone->ctx   = [$base, [
            'prefix' => '/' . ltrim(rtrim($base['prefix'], '/') . '/' . ltrim($prefix, '/'), '/'),
            'domain' => $domain ?? $base['domain'],
            'mw'     => array_merge($base['mw'], $this->expandAliases($mw)),
        ]];
        return $clone;
    }

    /* ───── dispatch ───── */
    public function handle(ServerRequestInterface $req): ResponseInterface
    {
        if (!$req instanceof HttpRequest) {
            // keep query-string, parsed-body & files exactly as they arrived
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

        $this->container->definitions()
            ->bind(ServerRequestInterface::class, $req)
            ->bind(HttpRequest::class, $req);

        try {
            [$route, $params] = $this->routes->match($req);
            foreach ($params as $k => $v) {
                $req = $req->withAttribute($k, $v);
            }

            $core = fn (ServerRequestInterface $r)
                => $this->ensureResponse($this->inv->invoke($route->getHandler(), [$r]));

            $pipeline = $this->wrapMiddleware(
                $core,
                [...$this->globalMw, ...$route->getMiddlewares()]
            );

            return $pipeline($req);

        } catch (RouteNotFoundException) {
            return new Response(404, new Stream('404 Not Found'));
        } catch (MethodNotAllowedException $e) {
            return (new Response(405, new Stream('405 Method Not Allowed')))
                ->withHeader('Allow', $e->getMessage());
        }
    }

    /* ───── helpers ───── */
    private function ensureResponse(mixed $out): ResponseInterface
    {
        return $out instanceof ResponseInterface
            ? $out
            : (new Response())->withBody(new Stream(is_scalar($out) ? (string)$out : json_encode($out)));
    }

    private function wrapMiddleware(callable $core, array $stack): callable
    {
        foreach (array_reverse($stack) as $def) {
            $mw  = $this->resolve($def);
            $inv = $this->inv;

            $core = static fn (ServerRequestInterface $r)
                => $inv->invoke($mw, [$r, $core]);
        }
        return $core;
    }

//    private function resolve(string|callable|object $def): callable
//    {
//        if (is_callable($def)) {
//            return $def;
//        }
//        if ($this->container->has($def)) {
//            return $this->container->get($def);
//        }
//        if (class_exists($def)) {
//            return $this->container->make($def);
//        }
//        throw new \RuntimeException("Cannot resolve middleware {$def}");
//    }

    private function expandAliases(array $list): array
    {
        return array_map(fn ($k) => $this->routeAliases[$k] ?? $k, $list);
    }
}
